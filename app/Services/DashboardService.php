<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\InquiryStatus;
use App\Enums\PeriodicReportStatus;
use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Notification;
use App\Models\PeriodicReport;
use App\Models\ReferralEntry;
use App\Models\ReportSection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Role-shaped aggregates for GET /api/v1/dashboard: the Ministry Attache,
 * Ministry HQ Officer, Ministry HQ Director, Ministry PS / Acting PS, and a
 * general view for every other ministry role.
 *
 * Every query relies on the Layer 2 models' global ministry scope, bound by
 * the ministry.scope middleware (CLAUDE.md Section 4, Rule 1); an attache is
 * additionally pinned to its own mission (BR-001). Trends are bucketed by
 * fiscal quarter (CLAUDE.md Section 8: Q1 Jul-Sep .. Q4 Apr-Jun), which
 * lines up with Postgres calendar quarters, so date_trunc('quarter') groups
 * them directly. The governance, HRM&D and administrator dashboards use
 * their own endpoints, not this service.
 */
class DashboardService
{
    public const int TREND_QUARTERS = 8;

    private const int RECENT_QUARTERS = 4;

    private const int LIST_LIMIT = 5;

    private const int INBOX_LIMIT = 6;

    private const int KPI_CYCLE_OPTIONS = 5;

    /** @var array<int, string> */
    private const array OPEN_INQUIRY_STATUSES = ['received', 'in_progress', 'pending_external_response'];

    /** @var array<int, string> */
    private const array PIPELINE_INQUIRY_STATUSES = ['draft', 'received', 'in_progress', 'pending_external_response', 'resolved'];

    /** @var array<int, string> */
    private const array OPEN_DIRECTIVE_STATUSES = ['issued', 'acknowledged', 'in_progress'];

    public function __construct(
        private readonly ReportService $reportService,
        private readonly KpiService $kpiService,
        private readonly DirectiveService $directiveService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, ?string $kpiCycle = null): array
    {
        return match ($user->role?->name) {
            'Ministry Attache' => $this->attache($user, $kpiCycle),
            'Ministry HQ Officer' => $this->hqOfficer($user),
            'Ministry HQ Director' => $this->leadership($user, 'director', $kpiCycle),
            'Ministry PS', 'Acting PS' => $this->leadership($user, 'executive', $kpiCycle),
            default => $this->general($user),
        };
    }

    // --- Views ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function attache(User $user, ?string $kpiCycle): array
    {
        $mission = $user->mission;
        $calendar = $this->calendar();

        if ($mission === null || $user->ministry_id === null) {
            return ['view' => 'attache', 'calendar' => $calendar, 'mission' => null];
        }

        $series = $this->quarterSeries();
        $from = $series->first()['start'];
        $currentQuarter = $series->last();
        $previousQuarter = $series->get($series->count() - 2);
        $recentFrom = $series->get($series->count() - self::RECENT_QUARTERS)['start'];

        $alerts = fn (): Builder => Alert::query()->where('mission_id', $mission->id);
        $inquiries = fn (): Builder => Inquiry::query()->where('mission_id', $mission->id);

        $alertCounts = $this->countsByQuarter($alerts(), 'created_at', $from);
        $inquiryCounts = $this->countsByQuarter($inquiries()->where('status', '!=', InquiryStatus::Draft->value), 'date_received', $from);

        $directives = Directive::query()
            ->with('mission')
            ->where('target_user_id', $user->id)
            ->whereIn('status', self::OPEN_DIRECTIVE_STATUSES)
            ->orderByRaw('target_completion_date asc nulls last')
            ->orderBy('created_at')
            ->get();

        return [
            'view' => 'attache',
            'calendar' => $calendar,
            'mission' => [
                'id' => $mission->id,
                'name' => $mission->name,
                'city' => $mission->city,
                'host_country' => $mission->host_country,
                'time_zone' => $mission->time_zone,
            ],
            'activity_trend' => $series->map(fn (array $quarter): array => [
                ...$this->presentQuarter($quarter),
                'alerts' => $alertCounts[$quarter['key']] ?? 0,
                'inquiries' => $inquiryCounts[$quarter['key']] ?? 0,
            ])->values()->all(),
            'alerts' => [
                'this_quarter' => $alertCounts[$currentQuarter['key']] ?? 0,
                'previous_quarter' => $alertCounts[$previousQuarter['key']] ?? 0,
                'outcomes' => $this->statusCounts(
                    $alerts()->where('created_at', '>=', $recentFrom),
                    array_map(fn (AlertStatus $status): string => $status->value, AlertStatus::cases()),
                ),
            ],
            'inquiries' => [
                'open' => $inquiries()->whereIn('status', self::OPEN_INQUIRY_STATUSES)->count(),
                'high_value_open' => $inquiries()->whereIn('status', self::OPEN_INQUIRY_STATUSES)->where('high_value_flag', true)->count(),
                'closed_this_quarter' => $inquiries()->where('status', InquiryStatus::Closed->value)->where('closed_at', '>=', $currentQuarter['start'])->count(),
                'pipeline' => $this->statusCounts($inquiries(), self::PIPELINE_INQUIRY_STATUSES),
            ],
            'directives' => [
                'open' => $directives->count(),
                'overdue' => $directives->filter(fn (Directive $directive): bool => $this->isOverdue($directive))->count(),
                'items' => $directives->take(self::LIST_LIMIT)->map($this->presentDirective(...))->values()->all(),
            ],
            'report' => $this->attacheReport($user, $calendar['reporting_period']),
            'kpi' => $this->attacheKpi($user, $kpiCycle),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hqOfficer(User $user): array
    {
        $series = $this->quarterSeries();
        $from = $series->first()['start'];
        $currentQuarter = $series->last();
        $recentFrom = $series->get($series->count() - self::RECENT_QUARTERS)['start'];
        $today = Carbon::today();

        $assignedAlerts = Alert::query()
            ->with('mission')
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', AlertStatus::Acknowledged->value)
            ->latest()
            ->get();

        $openInquiries = Inquiry::query()->whereIn('status', self::OPEN_INQUIRY_STATUSES)->get(['id', 'category', 'date_received']);

        $issuedDirectives = Directive::query()
            ->with(['mission', 'targetUser'])
            ->where('issued_by_user_id', $user->id)
            ->whereIn('status', self::OPEN_DIRECTIVE_STATUSES)
            ->orderByRaw('target_completion_date asc nulls last')
            ->get();

        $receivedCounts = $this->countsByQuarter(Inquiry::query()->where('status', '!=', InquiryStatus::Draft->value), 'date_received', $from);
        $closedCounts = $this->countsByQuarter(Inquiry::query()->where('status', InquiryStatus::Closed->value), 'closed_at', $from);

        return [
            'view' => 'hq_officer',
            'calendar' => $this->calendar(),
            'alerts' => [
                'awaiting_me' => $assignedAlerts->count(),
                'acknowledged_by_me' => Alert::query()->where('assigned_to_user_id', $user->id)->where('status', AlertStatus::Acknowledged->value)->count(),
                'items' => $assignedAlerts->take(self::LIST_LIMIT)->map($this->presentAlert(...))->values()->all(),
            ],
            'inquiries' => [
                'open' => $openInquiries->count(),
                'received_this_quarter' => $receivedCounts[$currentQuarter['key']] ?? 0,
                'closed_this_quarter' => $closedCounts[$currentQuarter['key']] ?? 0,
                'age' => $this->ageBuckets($openInquiries, $today),
                'categories' => $openInquiries
                    ->countBy('category')
                    ->sortDesc()
                    ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])
                    ->values()
                    ->all(),
            ],
            'inquiry_trend' => $series->map(fn (array $quarter): array => [
                ...$this->presentQuarter($quarter),
                'received' => $receivedCounts[$quarter['key']] ?? 0,
                'closed' => $closedCounts[$quarter['key']] ?? 0,
            ])->values()->all(),
            'directives' => [
                'issued_open' => $issuedDirectives->count(),
                'needs_follow_up' => $issuedDirectives->filter(fn (Directive $directive): bool => $this->isOverdue($directive) || $this->isStale($directive))->count(),
                'items' => $issuedDirectives->take(self::LIST_LIMIT)->map($this->presentDirective(...))->values()->all(),
            ],
            'referrals' => ReferralEntry::query()
                ->with('referralOrganisation')
                ->where('referral_date', '>=', $recentFrom)
                ->get(['id', 'inquiry_id', 'referral_organisation_id'])
                ->countBy(fn (ReferralEntry $entry): string => $entry->referralOrganisation?->name ?? '—')
                ->sortDesc()
                ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    /**
     * Ministry HQ Director ('director') and Ministry PS / Acting PS
     * ('executive'); the executive variant adds the routed-alert inbox.
     *
     * @return array<string, mixed>
     */
    private function leadership(User $user, string $variant, ?string $kpiCycle): array
    {
        $ministryId = $user->ministry_id;
        $calendar = $this->calendar();
        $series = $this->quarterSeries();
        $from = $series->first()['start'];
        $currentQuarter = $series->last();
        $previousQuarter = $series->get($series->count() - 2);
        $recentFrom = $series->get($series->count() - self::RECENT_QUARTERS)['start'];

        $alertsByType = Alert::query()
            ->where('created_at', '>=', $from)
            ->selectRaw("date_trunc('quarter', created_at)::date as quarter_start, intelligence_type, count(*) as aggregate")
            ->groupBy('quarter_start', 'intelligence_type')
            ->toBase()
            ->get()
            ->groupBy(fn (object $row): string => Carbon::parse($row->quarter_start)->toDateString());

        $receivedCounts = $this->countsByQuarter(Inquiry::query()->where('status', '!=', InquiryStatus::Draft->value), 'date_received', $from);
        $closedCounts = $this->countsByQuarter(Inquiry::query()->where('status', InquiryStatus::Closed->value), 'closed_at', $from);

        $alertTrend = $series->map(function (array $quarter) use ($alertsByType): array {
            $rows = $alertsByType->get($quarter['key'], collect());
            $byType = $rows->mapWithKeys(fn (object $row): array => [$row->intelligence_type => (int) $row->aggregate]);

            return [
                ...$this->presentQuarter($quarter),
                'opportunities' => $byType->get('opportunities', 0),
                'trade_barriers' => $byType->get('trade_barriers', 0),
                'other' => $byType->except(['opportunities', 'trade_barriers'])->sum(),
            ];
        })->values();

        $totalAlerts = fn (array $point): int => $point['opportunities'] + $point['trade_barriers'] + $point['other'];

        $compliance = $this->reportService->getComplianceDashboard($ministryId, $calendar['reporting_period']['label']);
        $stale = Directive::query()
            ->with(['mission', 'targetUser'])
            ->whereIn('status', self::OPEN_DIRECTIVE_STATUSES)
            ->get()
            ->filter(fn (Directive $directive): bool => $this->isOverdue($directive) || $this->isStale($directive))
            ->sortBy(fn (Directive $directive): string => $directive->target_completion_date?->toDateString() ?? '9999-12-31')
            ->values();

        $cycleLabel = $this->resolveKpiCycle($kpiCycle);

        $view = [
            'view' => 'leadership',
            'variant' => $variant,
            'calendar' => $calendar,
            'alerts' => [
                'this_quarter' => $totalAlerts($alertTrend->last()),
                'previous_quarter' => $totalAlerts($alertTrend->get($alertTrend->count() - 2)),
                'awaiting_action' => Alert::query()->where('status', AlertStatus::New->value)->count(),
                'unacknowledged' => Alert::query()->where('status', '!=', AlertStatus::Acknowledged->value)->count(),
            ],
            'alert_trend' => $alertTrend->all(),
            'inquiries' => [
                'open' => Inquiry::query()->whereIn('status', self::OPEN_INQUIRY_STATUSES)->count(),
                'high_value_open' => Inquiry::query()->whereIn('status', self::OPEN_INQUIRY_STATUSES)->where('high_value_flag', true)->count(),
                'closed_this_quarter' => $closedCounts[$currentQuarter['key']] ?? 0,
                'closed_previous_quarter' => $closedCounts[$previousQuarter['key']] ?? 0,
                'funnel' => $this->inquiryFunnel($recentFrom),
            ],
            'inquiry_trend' => $series->map(fn (array $quarter): array => [
                ...$this->presentQuarter($quarter),
                'received' => $receivedCounts[$quarter['key']] ?? 0,
                'closed' => $closedCounts[$quarter['key']] ?? 0,
            ])->values()->all(),
            'reports' => [
                'period' => $calendar['reporting_period'],
                'summary' => $compliance['summary'],
                'total' => count($compliance['missions']),
                'attention' => collect($compliance['missions'])
                    ->where('status', '!=', PeriodicReport::COMPLIANCE_ON_TIME)
                    ->sortBy(fn (array $row): int => match ($row['status']) {
                        PeriodicReport::COMPLIANCE_NOT_STARTED => 0,
                        PeriodicReport::COMPLIANCE_DRAFT => 1,
                        default => 2,
                    })
                    ->map(fn (array $row): array => [
                        'mission_id' => $row['mission_id'],
                        'mission_name' => $row['mission_name'],
                        'status' => $row['status'],
                        'submitted_at' => $row['submitted_at']?->toIso8601String(),
                        'report_id' => $row['report_id'],
                        'is_overdue' => $row['is_overdue'],
                        'days_overdue' => $row['days_overdue'],
                    ])
                    ->values()
                    ->all(),
                'trend' => $this->reportTrend($ministryId, $calendar['reporting_period']['label'], count($compliance['missions'])),
            ],
            'directives' => [
                'summary' => $this->directiveService->getSummary($ministryId),
                'needs_attention' => $stale->count(),
                'items' => $stale->take(self::LIST_LIMIT)->map($this->presentDirective(...))->values()->all(),
            ],
            'kpi' => [
                'cycle' => $this->cycleRef($cycleLabel),
                'options' => $this->kpiCycleOptions(),
                'missions' => collect($this->kpiService->missionStatusSummary($ministryId, $cycleLabel))
                    ->sortBy(fn (array $row): float => $row['total'] > 0 ? $row['counts']['on_track'] / $row['total'] : 0.0)
                    ->values()
                    ->all(),
            ],
            'top_countries' => $this->topValues(Alert::query()->where('created_at', '>=', $recentFrom), 'country'),
            'top_sectors' => $this->topValues(Alert::query()->where('created_at', '>=', $recentFrom)->whereNotNull('sector'), 'sector'),
        ];

        if ($variant === 'executive') {
            $view['alert_inbox'] = Alert::query()
                ->with('mission')
                ->where('status', AlertStatus::New->value)
                ->latest()
                ->limit(self::INBOX_LIMIT)
                ->get()
                ->map($this->presentAlert(...))
                ->values()
                ->all();
        }

        return $view;
    }

    /**
     * Every other ministry role (Designated Deputy, Ministry Publishing
     * Authority, Honorary Consul): alerts routed to the user and unread
     * notifications, both keyed on the user's own id.
     *
     * @return array<string, mixed>
     */
    private function general(User $user): array
    {
        $assigned = Alert::query()
            ->with('mission')
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', AlertStatus::Acknowledged->value)
            ->latest()
            ->get();

        $unread = Notification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->latest()
            ->get();

        return [
            'view' => 'general',
            'calendar' => $this->calendar(),
            'assigned_alerts' => [
                'count' => $assigned->count(),
                'items' => $assigned->take(self::LIST_LIMIT)->map($this->presentAlert(...))->values()->all(),
            ],
            'notifications' => [
                'unread' => $unread->count(),
                'items' => $unread->take(self::LIST_LIMIT)->map(fn (Notification $notification): array => [
                    'id' => $notification->id,
                    'message' => $notification->message,
                    'link' => $notification->link,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
        ];
    }

    // --- Sections ---------------------------------------------------------

    /**
     * The attache's own report for the open reporting period (FR-RPT-003,
     * BR-009): sections with narrative content or at least one data row
     * count as drafted; section completion is never enforced (CLAUDE.md
     * Section 8), so this is progress information only.
     *
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function attacheReport(User $user, array $period): array
    {
        $report = PeriodicReport::query()
            ->with('sections.dataRows')
            ->where('mission_id', $user->mission_id)
            ->where('reporting_period_label', $period['label'])
            ->first();

        return [
            'period' => $period,
            'id' => $report?->id,
            'status' => $report?->status->value ?? 'not_started',
            'is_late' => (bool) $report?->is_late,
            'submitted_at' => $report?->submitted_at?->toIso8601String(),
            'sections_total' => $report?->sections->count() ?? $this->activeTemplateSectionCount($user->ministry_id),
            'sections_drafted' => $report?->sections
                ->filter(fn (ReportSection $section): bool => trim((string) $section->content) !== '' || $section->dataRows->isNotEmpty())
                ->count() ?? 0,
        ];
    }

    /**
     * FR-KPI-010: the attache's own-mission KPI view, with the prior cycle's
     * actual alongside for the trend comparison the requirement names.
     *
     * @return array<string, mixed>
     */
    private function attacheKpi(User $user, ?string $kpiCycle): array
    {
        $cycleLabel = $this->resolveKpiCycle($kpiCycle);
        $previousLabel = $this->previousCycleLabel($cycleLabel);

        $previous = collect($this->kpiService->missionPerformance($user->mission, $user->ministry_id, $previousLabel))
            ->keyBy('kpi_definition_id');

        return [
            'cycle' => $this->cycleRef($cycleLabel),
            'previous_cycle' => $this->cycleRef($previousLabel),
            'options' => $this->kpiCycleOptions(),
            'kpis' => collect($this->kpiService->missionPerformance($user->mission, $user->ministry_id, $cycleLabel))
                ->map(fn (array $row): array => [
                    ...$row,
                    'previous_actual' => $previous->get($row['kpi_definition_id'])['actual'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * How far inquiries received in the last four quarters have travelled
     * through the configured workflow (CLAUDE.md Section 8). A status later
     * in the chain implies every earlier stage was reached; cancelled
     * inquiries are reported separately because they can leave from
     * several stages.
     *
     * @return array<string, int>
     */
    private function inquiryFunnel(Carbon $from): array
    {
        $counts = $this->statusCounts(
            Inquiry::query()->where('date_received', '>=', $from)->where('status', '!=', InquiryStatus::Draft->value),
            array_map(fn (InquiryStatus $status): string => $status->value, InquiryStatus::cases()),
        );

        return [
            'logged' => array_sum($counts),
            'worked_on' => $counts['in_progress'] + $counts['pending_external_response'] + $counts['resolved'] + $counts['closed'],
            'resolved' => $counts['resolved'] + $counts['closed'],
            'closed' => $counts['closed'],
            'cancelled' => $counts['cancelled'],
        ];
    }

    /**
     * Submitted-on-time / late / missing counts for the six reporting
     * periods up to and including the open one (BR-010). The open period's
     * "missing" reports are not yet due, so it is flagged with `is_open`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reportTrend(string $ministryId, string $openPeriodLabel, int $missionCount): array
    {
        $openStart = $this->cycleStart($openPeriodLabel);
        $labels = collect(range(5, 0))->map(fn (int $offset): string => $this->reportService->periodLabelFor($openStart->copy()->subMonths(3 * $offset)));

        $submitted = PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->whereIn('reporting_period_label', $labels)
            ->where('status', PeriodicReportStatus::Submitted->value)
            ->get(['reporting_period_label', 'is_late'])
            ->groupBy('reporting_period_label');

        return $labels->map(function (string $label) use ($submitted, $missionCount, $openPeriodLabel): array {
            $rows = $submitted->get($label, collect());
            $late = $rows->where('is_late', true)->count();
            $onTime = $rows->count() - $late;

            return [
                ...$this->cycleRef($label),
                'is_open' => $label === $openPeriodLabel,
                'on_time' => $onTime,
                'late' => $late,
                'missing' => max($missionCount - $onTime - $late, 0),
            ];
        })->values()->all();
    }

    // --- Fiscal calendar --------------------------------------------------

    /**
     * The quarter in progress and the reporting period whose deadline comes
     * next: the quarter just ended while its 15th-of-next-month deadline is
     * still ahead (ReportService::currentSubmissionPeriod()), otherwise the
     * quarter in progress.
     *
     * @return array<string, mixed>
     */
    public function calendar(): array
    {
        $today = Carbon::today();
        $quarter = $this->quarterRef($this->quarterStartFor($today));
        $reporting = $this->reportService->currentSubmissionPeriod();

        if ($today->gt($reporting['deadline'])) {
            $reporting = [
                'label' => $quarter['label'],
                'start' => $quarter['start'],
                'end' => $quarter['end'],
                'deadline' => $this->reportService->resolveSubmissionDeadline($quarter['end']),
            ];
        }

        return [
            'today' => $today->toDateString(),
            'quarter' => [
                ...$this->presentQuarter($quarter),
                'days_elapsed' => (int) $quarter['start']->diffInDays($today) + 1,
                'days_total' => (int) $quarter['start']->diffInDays($quarter['end']) + 1,
            ],
            'reporting_period' => [
                'label' => $reporting['label'],
                'start' => $reporting['start']->toDateString(),
                'end' => $reporting['end']->toDateString(),
                'deadline' => $reporting['deadline']->toDateString(),
                'days_remaining' => (int) $today->diffInDays($reporting['deadline']->copy()->startOfDay()),
            ],
        ];
    }

    /**
     * @return Collection<int, array{key: string, label: string, start: Carbon, end: Carbon}>
     */
    private function quarterSeries(): Collection
    {
        $current = $this->quarterStartFor(Carbon::today());

        return collect(range(self::TREND_QUARTERS - 1, 0))
            ->map(fn (int $offset): array => $this->quarterRef($current->copy()->subMonths(3 * $offset)))
            ->values();
    }

    private function quarterStartFor(Carbon $date): Carbon
    {
        return Carbon::create($date->year, intdiv($date->month - 1, 3) * 3 + 1, 1)->startOfDay();
    }

    /**
     * @return array{key: string, label: string, start: Carbon, end: Carbon}
     */
    private function quarterRef(Carbon $start): array
    {
        return [
            'key' => $start->toDateString(),
            'label' => $this->reportService->periodLabelFor($start),
            'start' => $start,
            'end' => $start->copy()->addMonths(3)->subDay(),
        ];
    }

    /**
     * @param  array{label: string, start: Carbon, end: Carbon}  $quarter
     * @return array{label: string, start: string, end: string}
     */
    private function presentQuarter(array $quarter): array
    {
        return [
            'label' => $quarter['label'],
            'start' => $quarter['start']->toDateString(),
            'end' => $quarter['end']->toDateString(),
        ];
    }

    /**
     * Start date of a "Q1 2027" or "H1 2027" label: Q1 and H1 begin in
     * July, Q2 in October, Q3 and H2 in January, Q4 in April, each in the
     * labelled year (the ReportService/KpiService convention).
     */
    private function cycleStart(string $label): Carbon
    {
        if (! preg_match('/^(Q[1-4]|H[12]) (\d{4})$/', $label, $matches)) {
            throw new InvalidArgumentException("Unrecognised cycle label '{$label}'.");
        }

        $month = match ($matches[1]) {
            'Q1', 'H1' => 7,
            'Q2' => 10,
            'Q3', 'H2' => 1,
            'Q4' => 4,
        };

        return Carbon::create((int) $matches[2], $month, 1)->startOfDay();
    }

    /**
     * @return array{label: string, start: string, end: string}
     */
    private function cycleRef(string $label): array
    {
        $start = $this->cycleStart($label);
        $months = str_starts_with($label, 'H') ? 6 : 3;

        return [
            'label' => $label,
            'start' => $start->toDateString(),
            'end' => $start->copy()->addMonths($months)->subDay()->toDateString(),
        ];
    }

    private function previousCycleLabel(string $label): string
    {
        $start = $this->cycleStart($label);

        if (str_starts_with($label, 'H')) {
            return $this->halfCycleLabel($start->copy()->subMonths(6));
        }

        return $this->reportService->periodLabelFor($start->copy()->subMonths(3));
    }

    private function halfCycleLabel(Carbon $start): string
    {
        return ($start->month === 7 ? 'H1 ' : 'H2 ').$start->year;
    }

    /**
     * Defaults to the latest completed half-yearly cycle (CLAUDE.md Section
     * 8: KPIs are half-yearly), since a cycle still in progress would show
     * every KPI short of a full-cycle target.
     */
    private function resolveKpiCycle(?string $requested): string
    {
        if ($requested !== null) {
            return $requested;
        }

        return $this->kpiCycleOptions()[1]['label'];
    }

    /**
     * The half-yearly cycle in progress, then the four before it.
     *
     * @return array<int, array{label: string, start: string, end: string}>
     */
    private function kpiCycleOptions(): array
    {
        $today = Carbon::today();
        $currentStart = Carbon::create($today->year, $today->month >= 7 ? 7 : 1, 1)->startOfDay();

        return collect(range(0, self::KPI_CYCLE_OPTIONS - 1))
            ->map(fn (int $offset): array => $this->cycleRef($this->halfCycleLabel($currentStart->copy()->subMonths(6 * $offset))))
            ->all();
    }

    // --- Query helpers ----------------------------------------------------

    /**
     * @return array<string, int> keyed by quarter start date (Y-m-d)
     */
    private function countsByQuarter(Builder $query, string $column, Carbon $from): array
    {
        return $query
            ->where($column, '>=', $from)
            ->selectRaw("date_trunc('quarter', {$column})::date as quarter_start, count(*) as aggregate")
            ->groupBy('quarter_start')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [Carbon::parse($row->quarter_start)->toDateString() => (int) $row->aggregate])
            ->all();
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<string, int>
     */
    private function statusCounts(Builder $query, array $statuses): array
    {
        $counts = $query
            ->whereIn('status', $statuses)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status');

        return collect($statuses)->mapWithKeys(fn (string $status): array => [$status => (int) ($counts[$status] ?? 0)])->all();
    }

    /**
     * @return array<int, array{name: string, count: int}>
     */
    private function topValues(Builder $query, string $column, int $limit = 6): array
    {
        return $query
            ->selectRaw("{$column} as name, count(*) as aggregate")
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->orderBy($column)
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * @param  Collection<int, Inquiry>  $inquiries
     * @return array<int, array{key: string, count: int}>
     */
    private function ageBuckets(Collection $inquiries, Carbon $today): array
    {
        $buckets = ['under_7_days' => 0, '7_to_30_days' => 0, '30_to_90_days' => 0, 'over_90_days' => 0];

        foreach ($inquiries as $inquiry) {
            $age = $inquiry->date_received?->diffInDays($today) ?? 0;

            $key = match (true) {
                $age < 7 => 'under_7_days',
                $age < 30 => '7_to_30_days',
                $age < 90 => '30_to_90_days',
                default => 'over_90_days',
            };

            $buckets[$key]++;
        }

        return collect($buckets)->map(fn (int $count, string $key): array => ['key' => $key, 'count' => $count])->values()->all();
    }

    private function activeTemplateSectionCount(string $ministryId): int
    {
        try {
            return $this->reportService->getActiveTemplate($ministryId)->count();
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * Delegates to the Directive model's single derived-flag implementation
     * so the dashboards agree with the Directives module.
     */
    private function isOverdue(Directive $directive): bool
    {
        return $directive->isOverdue();
    }

    private function isStale(Directive $directive): bool
    {
        return $directive->isStale();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAlert(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'reference_number' => $alert->reference_number,
            'country' => $alert->country,
            'intelligence_type' => $alert->intelligence_type,
            'urgency' => $alert->urgency,
            'status' => $alert->status->value,
            'mission_name' => $alert->mission?->name,
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDirective(Directive $directive): array
    {
        return [
            'id' => $directive->id,
            'description' => $directive->description,
            'status' => $directive->status->value,
            'mission_name' => $directive->mission?->name,
            'target_name' => $directive->targetUser?->full_name,
            'target_completion_date' => $directive->target_completion_date?->toDateString(),
            'is_overdue' => $this->isOverdue($directive),
            'is_stale' => $this->isStale($directive),
        ];
    }
}
