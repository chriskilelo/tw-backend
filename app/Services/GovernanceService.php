<?php

namespace App\Services;

use App\Enums\PeriodicReportStatus;
use App\Models\Alert;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\User;
use App\Support\KpiPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CLAUDE.md Section 11 / FR-HOM-001 to 003, FR-MFA-001 to 003: read-only
 * aggregation of the submissions a ministry attache originates (alerts,
 * inquiries and submitted periodic reports), for the two mission-governance
 * views. Nothing here ever mutates a record.
 *
 * The record types are exactly those the requirements name: FR-HOM-001
 * ("submissions originating from the ministry attache(s)"), FR-HOM-002
 * ("alerts, inquiries, and reports"), TW-ARCH-001 Section 5.3 and
 * FR-MFA-001 AC2. Directives are HQ-to-field tasking, not attache
 * submissions, and the directive engine's own visibility rules exclude
 * these roles, so they are not counted. A draft report is the mission's
 * work in progress (FR-RPT-017), never a submission.
 *
 * Every figure comes from one UNION ALL over the three tables, filtered,
 * grouped and paginated by the database. The models are read
 * withoutGlobalScopes() on purpose: both views are cross-department by
 * definition, so who sees which rows is decided here, by the mission the
 * caller passes in and the filters, never by the caller's role. Callers
 * authorise first (MissionPolicy).
 *
 * Periods are fiscal quarters (CLAUDE.md Section 8: Q1 Jul-Sep .. Q4
 * Apr-Jun, labelled by the start month's year), whose boundaries coincide
 * with calendar quarters, so Postgres's date_trunc('quarter') buckets them.
 * A submission is dated when it entered the system: an alert or inquiry at
 * creation, a report at submission.
 */
class GovernanceService
{
    public const string TYPE_ALERT = 'alert';

    public const string TYPE_INQUIRY = 'inquiry';

    public const string TYPE_REPORT = 'periodic_report';

    /**
     * @var array<int, string>
     */
    public const array TYPES = [self::TYPE_ALERT, self::TYPE_INQUIRY, self::TYPE_REPORT];

    /**
     * A submitted report's status in these views is its timeliness
     * (FR-RPT-016), the compliance vocabulary of FR-RPT-018.
     */
    public const string REPORT_ON_TIME = 'submitted_on_time';

    public const string REPORT_LATE = 'submitted_late';

    private const int MISSION_TREND_QUARTERS = 6;

    private const int NETWORK_TREND_QUARTERS = 8;

    private const int RECENT_LIMIT = 8;

    private const int EXCERPT_LENGTH = 160;

    // --- Head of Mission / Deputy Head of Mission (FR-HOM-001 to 003) --------

    /**
     * FR-HOM-001: the mission's submissions, newest first, each with its
     * type, date, submitting officer, department and a short summary, plus
     * the in-app link to the full record (AC2). Optionally narrowed to one
     * department (AC3), record type, status or fiscal quarter.
     *
     * @param  array{ministry_id?: ?string, type?: ?string, status?: ?string, period?: ?KpiPeriod}  $filters
     */
    public function missionActivityFeed(Mission $mission, array $filters, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $direction = $sort === 'date' ? 'asc' : 'desc';

        $paginator = $this->filtered([...$filters, 'mission_id' => $mission->id])
            ->select(['type', 'id', 'reference', 'mission_id', 'ministry_id', 'officer_id', 'status', 'occurred_at'])
            ->orderBy('occurred_at', $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items());
        $details = $this->detailsFor($rows);
        $officers = User::query()->withTrashed()->whereIn('id', $rows->pluck('officer_id')->filter()->unique()->values())->pluck('full_name', 'id');
        $ministries = Ministry::query()->whereIn('id', $rows->pluck('ministry_id')->unique()->values())->pluck('name', 'id');

        return $paginator->setCollection($rows->map(fn (object $row): array => [
            'type' => $row->type,
            'id' => $row->id,
            'reference' => $row->reference,
            'status' => $row->status,
            'date' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            'ministry' => ['id' => $row->ministry_id, 'name' => $ministries->get($row->ministry_id)],
            'submitting_officer' => $officers->get($row->officer_id),
            'summary' => $details->get("{$row->type}:{$row->id}"),
            'link' => $this->linkFor($row->type, $row->id),
        ]));
    }

    /**
     * FR-HOM-002: counts by record type and status for the current and the
     * prior fiscal quarter, with the mission's departments and a six-quarter
     * trend. FR-MFA-002 reuses it without the attaches' names.
     *
     * @return array<string, mixed>
     */
    public function missionActivitySummary(Mission $mission, ?string $ministryId = null, bool $withAttacheNames = true): array
    {
        $today = CarbonImmutable::now();
        $quarters = $this->recentQuarters($today, self::MISSION_TREND_QUARTERS);
        $rows = $this->countRows(['mission_id' => $mission->id, 'ministry_id' => $ministryId, 'since' => $quarters[0]->start()]);
        $current = $quarters[count($quarters) - 1];

        return [
            'mission' => [
                'id' => $mission->id,
                'name' => $mission->name,
                'city' => $mission->city,
                'host_country' => $mission->host_country,
                'active' => (bool) $mission->active,
            ],
            'ministry_id' => $ministryId,
            'departments' => $this->departmentsAt($mission, $withAttacheNames),
            'current_period' => $this->periodSummary($rows, $current, $today),
            'prior_period' => $this->periodSummary($rows, $current->previous(), $today),
            'trend' => array_map(fn (KpiPeriod $quarter): array => $this->trendPoint($rows, $quarter, $today), $quarters),
            'last_activity_at' => $this->latestActivity(['mission_id' => $mission->id, 'ministry_id' => $ministryId]),
        ];
    }

    // --- MFA HQ Officer / MFA Principal Secretary (FR-MFA-001 to 003) -------

    /**
     * FR-MFA-001: submission counts by mission, by department and by fiscal
     * quarter, to date or within one quarter, optionally for one department.
     * Counts only: no reference, status, officer or content (AC2).
     *
     * @return array<string, mixed>
     */
    public function mfaAwarenessSummary(?string $ministryId = null, ?KpiPeriod $period = null): array
    {
        $today = CarbonImmutable::now();
        $quarters = $this->recentQuarters($today, self::NETWORK_TREND_QUARTERS);
        $rows = $this->countRows(['ministry_id' => $ministryId, 'period' => $period]);
        $trendRows = $period === null ? $rows : $this->countRows(['ministry_id' => $ministryId, 'since' => $quarters[0]->start()]);
        $lastActivity = $this->latestActivityBy('mission_id', ['ministry_id' => $ministryId, 'period' => $period]);

        $missions = Mission::query()->orderBy('name')->get(['id', 'name', 'city', 'host_country', 'active']);
        $ministries = Ministry::query()->orderBy('name')->get(['id', 'name', 'active']);
        $ministriesWithActivity = $this->activity()->distinct()->pluck('ministry_id');
        $rowsByMission = $rows->groupBy('mission_id');
        $rowsByMinistry = $rows->groupBy('ministry_id');

        return [
            'scope' => [
                'ministry_id' => $ministryId,
                'period' => $period === null ? null : $this->periodRef($period, $today),
            ],
            'current_quarter' => $this->periodRef($quarters[count($quarters) - 1], $today),
            'ministries' => $ministries
                ->filter(fn (Ministry $ministry): bool => $ministry->active || $ministriesWithActivity->contains($ministry->id))
                ->map(fn (Ministry $ministry): array => ['id' => $ministry->id, 'name' => $ministry->name])
                ->values(),
            'total' => (int) $rows->sum('total'),
            'by_type' => $this->byType($rows),
            'by_mission' => $missions
                ->filter(fn (Mission $mission): bool => $mission->active || $rowsByMission->has($mission->id))
                ->map(fn (Mission $mission): array => [
                    'mission_id' => $mission->id,
                    'mission_name' => $mission->name,
                    'city' => $mission->city,
                    'host_country' => $mission->host_country,
                    'active' => (bool) $mission->active,
                    'total' => (int) $rowsByMission->get($mission->id, collect())->sum('total'),
                    'by_type' => $this->byType($rowsByMission->get($mission->id, collect())),
                    'last_activity_at' => $lastActivity->get($mission->id),
                ])
                ->sortBy([['total', 'desc'], ['mission_name', 'asc']])
                ->values(),
            'by_ministry' => $ministries
                ->filter(fn (Ministry $ministry): bool => ($ministryId === null || $ministry->id === $ministryId) && ($ministry->active || $rowsByMinistry->has($ministry->id)))
                ->map(fn (Ministry $ministry): array => [
                    'ministry_id' => $ministry->id,
                    'ministry_name' => $ministry->name,
                    'total' => (int) $rowsByMinistry->get($ministry->id, collect())->sum('total'),
                    'by_type' => $this->byType($rowsByMinistry->get($ministry->id, collect())),
                    'missions_reporting' => $rowsByMinistry->get($ministry->id, collect())->pluck('mission_id')->unique()->count(),
                ])
                ->sortBy([['total', 'desc'], ['ministry_name', 'asc']])
                ->values(),
            'by_period' => array_map(fn (KpiPeriod $quarter): array => $this->trendPoint($trendRows, $quarter, $today), $quarters),
        ];
    }

    /**
     * FR-MFA-002: one mission's summary metrics, the same figures FR-HOM-002
     * gives its Head of Mission, plus its latest submissions as metadata.
     *
     * @return array<string, mixed>
     */
    public function missionDrillDown(Mission $mission, ?string $ministryId = null): array
    {
        return [
            ...$this->missionActivitySummary($mission, $ministryId, withAttacheNames: false),
            'recent' => $this->submissionLog(['mission_id' => $mission->id, 'ministry_id' => $ministryId], self::RECENT_LIMIT, 1)->items(),
        ];
    }

    /**
     * FR-MFA-001 AC2: the submission log an MFA officer may browse, each
     * entry showing only its type, date, mission and department. No record
     * id is returned, so an entry cannot be turned into a request for the
     * record itself.
     *
     * @param  array{mission_id?: ?string, ministry_id?: ?string, type?: ?string, period?: ?KpiPeriod}  $filters
     */
    public function submissionLog(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $paginator = $this->filtered($filters)
            ->select(['type', 'mission_id', 'ministry_id', 'occurred_at'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items());
        $missions = Mission::query()->whereIn('id', $rows->pluck('mission_id')->unique()->values())->pluck('name', 'id');
        $ministries = Ministry::query()->whereIn('id', $rows->pluck('ministry_id')->unique()->values())->pluck('name', 'id');

        return $paginator->setCollection($rows->map(fn (object $row): array => [
            'type' => $row->type,
            'date' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            'mission' => ['id' => $row->mission_id, 'name' => $missions->get($row->mission_id)],
            'ministry' => ['id' => $row->ministry_id, 'name' => $ministries->get($row->ministry_id)],
        ]));
    }

    /**
     * FR-MFA-003: every mission and department compared for one fiscal
     * quarter (default: the current one) against the quarter before, with a
     * mission-by-department breakdown for the cross-department comparison.
     *
     * @return array<string, mixed>
     */
    public function nationalOverview(?KpiPeriod $period = null): array
    {
        $today = CarbonImmutable::now();
        $period ??= KpiPeriod::quarterContaining($today);
        $prior = $period->previous();
        $rows = $this->countRows(['since' => $prior->start(), 'until' => $period->end()]);
        $currentRows = $rows->where('quarter', $period->label);
        $priorRows = $rows->where('quarter', $prior->label);

        $missions = Mission::query()->orderBy('name')->get(['id', 'name', 'city', 'host_country', 'active']);
        $ministries = Ministry::query()->orderBy('name')->get(['id', 'name', 'active']);
        $currentByMission = $currentRows->groupBy('mission_id');
        $priorByMission = $priorRows->groupBy('mission_id');
        $currentByMinistry = $currentRows->groupBy('ministry_id');
        $priorByMinistry = $priorRows->groupBy('ministry_id');
        $hasRows = $rows->groupBy('ministry_id');

        $listedMinistries = $ministries->filter(fn (Ministry $ministry): bool => $ministry->active || $hasRows->has($ministry->id))->values();

        return [
            'period' => $this->periodRef($period, $today),
            'comparison_period' => $this->periodRef($prior, $today),
            'ministries' => $listedMinistries->map(fn (Ministry $ministry): array => ['id' => $ministry->id, 'name' => $ministry->name])->values(),
            'totals' => [
                'current' => ['total' => (int) $currentRows->sum('total'), 'by_type' => $this->byType($currentRows)],
                'prior' => ['total' => (int) $priorRows->sum('total'), 'by_type' => $this->byType($priorRows)],
            ],
            'missions' => $missions
                ->filter(fn (Mission $mission): bool => $mission->active || $currentByMission->has($mission->id) || $priorByMission->has($mission->id))
                ->map(function (Mission $mission) use ($currentByMission, $priorByMission, $listedMinistries): array {
                    $current = $currentByMission->get($mission->id, collect());
                    $prior = $priorByMission->get($mission->id, collect());

                    return [
                        'mission_id' => $mission->id,
                        'mission_name' => $mission->name,
                        'host_country' => $mission->host_country,
                        'active' => (bool) $mission->active,
                        'current' => [
                            'total' => (int) $current->sum('total'),
                            'by_type' => $this->byType($current),
                            'by_ministry' => $listedMinistries->mapWithKeys(fn (Ministry $ministry): array => [
                                $ministry->id => (int) $current->where('ministry_id', $ministry->id)->sum('total'),
                            ]),
                        ],
                        'prior' => ['total' => (int) $prior->sum('total'), 'by_type' => $this->byType($prior)],
                    ];
                })
                ->values(),
            'departments' => $listedMinistries->map(fn (Ministry $ministry): array => [
                'ministry_id' => $ministry->id,
                'ministry_name' => $ministry->name,
                'current' => [
                    'total' => (int) $currentByMinistry->get($ministry->id, collect())->sum('total'),
                    'by_type' => $this->byType($currentByMinistry->get($ministry->id, collect())),
                    'missions_reporting' => $currentByMinistry->get($ministry->id, collect())->pluck('mission_id')->unique()->count(),
                ],
                'prior' => [
                    'total' => (int) $priorByMinistry->get($ministry->id, collect())->sum('total'),
                    'by_type' => $this->byType($priorByMinistry->get($ministry->id, collect())),
                ],
            ])->values(),
        ];
    }

    // --- Query building ---------------------------------------------------------

    /**
     * One row per submission across the three engines, with the same
     * columns whatever the type.
     */
    private function activity(): Builder
    {
        $alerts = Alert::query()->withoutGlobalScopes()->toBase()->selectRaw(
            "'alert'::text as type, id, reference_number::text as reference, mission_id, ministry_id, submitted_by_user_id as officer_id, status::text as status, created_at as occurred_at"
        );

        $inquiries = Inquiry::query()->withoutGlobalScopes()->toBase()->selectRaw(
            "'inquiry'::text as type, id, reference_number::text as reference, mission_id, ministry_id, logged_by_user_id as officer_id, status::text as status, created_at as occurred_at"
        );

        $reports = PeriodicReport::query()->withoutGlobalScopes()->toBase()
            ->where('status', PeriodicReportStatus::Submitted->value)
            ->whereNotNull('submitted_at')
            ->selectRaw(
                "'periodic_report'::text as type, id, reporting_period_label::text as reference, mission_id, ministry_id, authored_by_user_id as officer_id, (case when is_late then ? else ? end)::text as status, submitted_at as occurred_at",
                [self::REPORT_LATE, self::REPORT_ON_TIME],
            );

        return DB::query()->fromSub($alerts->unionAll($inquiries)->unionAll($reports), 'activity');
    }

    /**
     * @param  array{mission_id?: ?string, ministry_id?: ?string, type?: ?string, status?: ?string, period?: ?KpiPeriod, since?: ?CarbonImmutable, until?: ?CarbonImmutable}  $filters
     */
    private function filtered(array $filters): Builder
    {
        $period = $filters['period'] ?? null;

        return $this->activity()
            ->when($filters['mission_id'] ?? null, fn (Builder $query, string $missionId) => $query->where('mission_id', $missionId))
            ->when($filters['ministry_id'] ?? null, fn (Builder $query, string $ministryId) => $query->where('ministry_id', $ministryId))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($period !== null, fn (Builder $query) => $query->whereBetween('occurred_at', [$period->start()->startOfDay(), $period->end()->endOfDay()]))
            ->when($filters['since'] ?? null, fn (Builder $query, CarbonImmutable $since) => $query->where('occurred_at', '>=', $since->startOfDay()))
            ->when($filters['until'] ?? null, fn (Builder $query, CarbonImmutable $until) => $query->where('occurred_at', '<=', $until->endOfDay()));
    }

    /**
     * Submission counts grouped by type, mission, department, status and
     * fiscal quarter: every summary on both views is a roll-up of these.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object{type: string, mission_id: string, ministry_id: string, status: string, quarter: string, total: int}>
     */
    private function countRows(array $filters): Collection
    {
        return $this->filtered($filters)
            ->selectRaw("type, mission_id, ministry_id, status, (date_trunc('quarter', occurred_at))::date as quarter_start, count(*) as total")
            ->groupByRaw("type, mission_id, ministry_id, status, (date_trunc('quarter', occurred_at))::date")
            ->get()
            ->map(fn (object $row): object => (object) [
                'type' => $row->type,
                'mission_id' => $row->mission_id,
                'ministry_id' => $row->ministry_id,
                'status' => $row->status,
                'quarter' => KpiPeriod::quarterContaining(CarbonImmutable::parse($row->quarter_start))->label,
                'total' => (int) $row->total,
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function latestActivity(array $filters): ?string
    {
        $latest = $this->filtered($filters)->max('occurred_at');

        return $latest === null ? null : CarbonImmutable::parse($latest)->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<string, string>
     */
    private function latestActivityBy(string $column, array $filters): Collection
    {
        return $this->filtered($filters)
            ->selectRaw("{$column} as grouping_key, max(occurred_at) as latest")
            ->groupBy($column)
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->grouping_key => CarbonImmutable::parse($row->latest)->toIso8601String()]);
    }

    // --- Roll-ups -----------------------------------------------------------------

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, int>
     */
    private function byType(Collection $rows): array
    {
        return collect(self::TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => (int) $rows->where('type', $type)->sum('total')])
            ->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, array<string, int>>
     */
    private function byStatus(Collection $rows): array
    {
        return collect(self::TYPES)
            ->mapWithKeys(fn (string $type): array => [
                $type => $rows->where('type', $type)
                    ->groupBy('status')
                    ->map(fn (Collection $group): int => (int) $group->sum('total'))
                    ->sortDesc()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function periodSummary(Collection $rows, KpiPeriod $quarter, CarbonImmutable $today): array
    {
        $periodRows = $rows->where('quarter', $quarter->label);

        return [
            ...$this->periodRef($quarter, $today),
            'total' => (int) $periodRows->sum('total'),
            'by_type' => $this->byType($periodRows),
            'by_status' => $this->byStatus($periodRows),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function trendPoint(Collection $rows, KpiPeriod $quarter, CarbonImmutable $today): array
    {
        $periodRows = $rows->where('quarter', $quarter->label);

        return [
            ...$this->periodRef($quarter, $today),
            'total' => (int) $periodRows->sum('total'),
            'by_type' => $this->byType($periodRows),
        ];
    }

    /**
     * @return array{label: string, start: string, end: string, is_partial: bool}
     */
    private function periodRef(KpiPeriod $period, CarbonImmutable $today): array
    {
        return [
            'label' => $period->label,
            'start' => $period->start()->toDateString(),
            'end' => $period->end()->toDateString(),
            'is_partial' => $period->phase($today) === KpiPeriod::IN_PROGRESS,
        ];
    }

    /**
     * The $count fiscal quarters ending with the one containing $today,
     * oldest first.
     *
     * @return array<int, KpiPeriod>
     */
    private function recentQuarters(CarbonImmutable $today, int $count): array
    {
        $quarters = [KpiPeriod::quarterContaining($today)];

        while (count($quarters) < $count) {
            array_unshift($quarters, $quarters[0]->previous());
        }

        return $quarters;
    }

    // --- Presentation ---------------------------------------------------------

    /**
     * The departments posted to a mission (mission_ministry_links, BR-004)
     * and any other department with submissions there. The attache's name
     * is for the Head of Mission only; the MFA drill-down gets the posting
     * flag (FR-MFA-001 AC2).
     *
     * @return array<int, array<string, mixed>>
     */
    private function departmentsAt(Mission $mission, bool $withAttacheNames): array
    {
        $links = MissionMinistryLink::query()
            ->with(['ministry', 'activeAttache'])
            ->where('mission_id', $mission->id)
            ->get()
            ->keyBy('ministry_id');

        $withActivity = $this->filtered(['mission_id' => $mission->id])->distinct()->pluck('ministry_id');
        $ministries = Ministry::query()->whereIn('id', $links->keys()->merge($withActivity)->unique()->values())->orderBy('name')->get(['id', 'name', 'active']);

        return $ministries->map(function (Ministry $ministry) use ($links, $withActivity, $withAttacheNames): array {
            $attache = $links->get($ministry->id)?->activeAttache;

            return [
                'id' => $ministry->id,
                'name' => $ministry->name,
                'active' => (bool) $ministry->active,
                'posted' => $attache !== null,
                'attache' => $withAttacheNames && $attache !== null ? ['full_name' => $attache->full_name] : null,
                'has_activity' => $withActivity->contains($ministry->id),
            ];
        })->values()->all();
    }

    /**
     * A short, type-specific summary of each feed row (FR-HOM-001 AC1). An
     * inquiry shows its inquirer's organisation, not the inquirer's name:
     * the person's details are one click away, on the full record.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<string, array<string, mixed>>
     */
    private function detailsFor(Collection $rows): Collection
    {
        $idsOf = fn (string $type): array => $rows->where('type', $type)->pluck('id')->all();

        if ($rows->isEmpty()) {
            return collect();
        }

        $alerts = Alert::query()->withoutGlobalScopes()->toBase()
            ->whereIn('id', $idsOf(self::TYPE_ALERT))
            ->get(['id', 'country', 'sector', 'product_category', 'intelligence_type', 'urgency', 'product_description'])
            ->mapWithKeys(fn (object $alert): array => [self::TYPE_ALERT.":{$alert->id}" => [
                'country' => $alert->country,
                'intelligence_type' => $alert->intelligence_type,
                'sector' => $alert->sector,
                'product_category' => $alert->product_category,
                'urgency' => $alert->urgency,
                'excerpt' => $this->excerpt($alert->product_description),
            ]]);

        $inquiries = Inquiry::query()->withoutGlobalScopes()->toBase()
            ->whereIn('id', $idsOf(self::TYPE_INQUIRY))
            ->get(['id', 'category', 'sub_type', 'inquirer_organisation', 'product_or_sector', 'high_value_flag', 'description'])
            ->mapWithKeys(fn (object $inquiry): array => [self::TYPE_INQUIRY.":{$inquiry->id}" => [
                'category' => $inquiry->category,
                'sub_type' => $inquiry->sub_type,
                'inquirer_organisation' => $inquiry->inquirer_organisation,
                'product_or_sector' => $inquiry->product_or_sector,
                'high_value' => (bool) $inquiry->high_value_flag,
                'excerpt' => $this->excerpt($inquiry->description),
            ]]);

        $reports = PeriodicReport::query()->withoutGlobalScopes()->toBase()
            ->whereIn('id', $idsOf(self::TYPE_REPORT))
            ->get(['id', 'reporting_period_label', 'period_start_date', 'period_end_date', 'is_late'])
            ->mapWithKeys(fn (object $report): array => [self::TYPE_REPORT.":{$report->id}" => [
                'period_label' => $report->reporting_period_label,
                'period_start' => CarbonImmutable::parse($report->period_start_date)->toDateString(),
                'period_end' => CarbonImmutable::parse($report->period_end_date)->toDateString(),
                'is_late' => (bool) $report->is_late,
            ]]);

        return $alerts->merge($inquiries)->merge($reports);
    }

    private function excerpt(?string $text): ?string
    {
        $flattened = Str::squish((string) $text);

        return $flattened === '' ? null : Str::limit($flattened, self::EXCERPT_LENGTH);
    }

    private function linkFor(string $type, string $id): string
    {
        return match ($type) {
            self::TYPE_ALERT => "/alerts/{$id}",
            self::TYPE_INQUIRY => "/inquiries/{$id}",
            default => "/reports/{$id}",
        };
    }
}
