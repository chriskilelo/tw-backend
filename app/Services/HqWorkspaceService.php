<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\DirectiveStatus;
use App\Enums\InquiryEventType;
use App\Enums\PeriodicReportStatus;
use App\Http\Resources\DirectiveResource;
use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\User;
use App\Support\HqPortfolio;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * FR-SDT-012 to 015 (TW-ARCH-001 L3-C-006): the Ministry HQ Officer's
 * workspace — alerts delegated to them, inquiries attaches notified HQ of
 * (FR-INQ-009), the directives they issued, and submitted quarterly
 * reports — defaulting to their configured portfolio (App\Support\HqPortfolio)
 * with a switch to the whole department.
 *
 * The portfolio narrows the department-wide views only: the alert feed,
 * the notified inquiries and the reports. Work that belongs to the officer
 * personally (alerts assigned to them, directives they issued) is always
 * listed in full, each row flagged in_portfolio, so a portfolio can never
 * hide an assignment.
 *
 * Every query is confined to the officer's own department explicitly, on
 * top of the ministry global scope the ministry.scope middleware binds
 * (NFR-SEC-006): the workspace stays inside the department even if it is
 * ever reached without that middleware. Row actions are computed through
 * the engines' own policies (AlertPolicy, DirectivePolicy), so the
 * workspace offers exactly what the API accepts.
 */
class HqWorkspaceService
{
    public const string SCOPE_PORTFOLIO = 'portfolio';

    public const string SCOPE_DEPARTMENT = 'department';

    /** @var array<int, string> */
    public const array SCOPES = [self::SCOPE_PORTFOLIO, self::SCOPE_DEPARTMENT];

    public const string ALERTS_AWAITING = 'awaiting';

    public const string ALERTS_ACKNOWLEDGED = 'acknowledged';

    public const string ALERTS_FEED = 'feed';

    /** @var array<int, string> */
    public const array ALERT_QUEUES = [self::ALERTS_AWAITING, self::ALERTS_ACKNOWLEDGED, self::ALERTS_FEED];

    /**
     * Inquiry workflow statuses grouped for the workspace; "all" applies no
     * status filter.
     *
     * @var array<string, array<int, string>>
     */
    public const array INQUIRY_STATUS_GROUPS = [
        'open' => ['received', 'in_progress', 'pending_external_response'],
        'resolved' => ['resolved'],
        'closed' => ['closed', 'cancelled'],
        'all' => [],
    ];

    public const string DIRECTIVES_ATTENTION = 'attention';

    public const string DIRECTIVES_AWAITING_CLOSURE = 'awaiting_closure';

    public const string DIRECTIVES_OPEN = 'open';

    public const string DIRECTIVES_ALL = 'all';

    /** @var array<int, string> */
    public const array DIRECTIVE_QUEUES = [self::DIRECTIVES_ATTENTION, self::DIRECTIVES_AWAITING_CLOSURE, self::DIRECTIVES_OPEN, self::DIRECTIVES_ALL];

    private const int EXCERPT_LENGTH = 180;

    public function __construct(private readonly DirectiveService $directiveService) {}

    /**
     * The scope the workspace actually uses: the requested one, except that
     * "portfolio" (also the default) falls back to "department" when the
     * officer has no portfolio configured.
     *
     * @return array{scope: string, portfolio: HqPortfolio}
     */
    public function context(User $officer, ?string $requestedScope): array
    {
        $portfolio = HqPortfolio::forUser($officer);
        $scope = $requestedScope === self::SCOPE_DEPARTMENT || $portfolio->isEmpty() ? self::SCOPE_DEPARTMENT : self::SCOPE_PORTFOLIO;

        return ['scope' => $scope, 'portfolio' => $portfolio];
    }

    /**
     * GET /sdt/hq-workspace: the portfolio, the effective scope, a count per
     * section queue (with the scope and mission filter applied, so every
     * count equals its list's total) and the filter options.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(User $officer, array $filters): array
    {
        ['scope' => $scope, 'portfolio' => $portfolio] = $this->context($officer, $filters['scope'] ?? null);
        $missionId = $filters['mission_id'] ?? null;
        $missions = $this->departmentMissions($officer);
        $latestPeriod = $this->latestReportPeriod($officer, $scope, $portfolio, $missionId);

        return [
            'department' => $officer->ministry === null ? null : ['id' => $officer->ministry->id, 'name' => $officer->ministry->name],
            'scope' => $scope,
            'portfolio' => [
                'configured' => ! $portfolio->isEmpty(),
                'missions' => $missions
                    ->filter(fn (Mission $mission): bool => in_array($mission->id, $portfolio->missionIds, true))
                    ->map($this->presentMission(...))
                    ->values()
                    ->all(),
                'countries' => $portfolio->countries,
                'sectors' => $portfolio->sectors,
                'covers_missions' => $portfolio->hasGeography(),
            ],
            'counts' => [
                'alerts' => collect(self::ALERT_QUEUES)
                    ->mapWithKeys(fn (string $queue): array => [$queue => $this->alertQueue($officer, $queue, $scope, $portfolio, $missionId)->count()])
                    ->all(),
                'inquiries' => [
                    ...collect(array_keys(self::INQUIRY_STATUS_GROUPS))
                        ->mapWithKeys(fn (string $group): array => [$group => $this->inquiryQueue($officer, $group, $scope, $portfolio, $missionId)->count()])
                        ->all(),
                    'high_value_open' => $this->inquiryQueue($officer, 'open', $scope, $portfolio, $missionId)->where('high_value_flag', true)->count(),
                ],
                'directives' => [
                    ...collect(self::DIRECTIVE_QUEUES)
                        ->mapWithKeys(fn (string $queue): array => [$queue => $this->directiveQueue($officer, $queue, $missionId)->count()])
                        ->all(),
                    'overdue' => $this->directiveQueue($officer, self::DIRECTIVES_ALL, $missionId)->tap($this->overdue(...))->count(),
                    'stale' => $this->directiveQueue($officer, self::DIRECTIVES_ALL, $missionId)->tap($this->stale(...))->count(),
                ],
                'reports' => [
                    'submitted' => $this->reportQueue($officer, $scope, $portfolio, $missionId)->count(),
                    'latest_period' => $latestPeriod,
                ],
            ],
            'filter_options' => [
                'missions' => $missions->map(fn (Mission $mission): array => [
                    ...$this->presentMission($mission),
                    'in_portfolio' => $portfolio->coversMission($mission->id, $mission->host_country),
                ])->values()->all(),
                'periods' => $this->reportQueue($officer, self::SCOPE_DEPARTMENT, $portfolio, null)
                    ->reorder()
                    ->select('reporting_period_label', 'period_start_date')
                    ->distinct()
                    ->orderByDesc('period_start_date')
                    ->limit(12)
                    ->pluck('reporting_period_label')
                    ->all(),
            ],
            'filters' => ['scope' => $filters['scope'] ?? null, 'mission_id' => $missionId],
        ];
    }

    /**
     * GET /sdt/hq-workspace/alerts.
     *
     * @param  array<string, mixed>  $filters
     */
    public function alerts(User $officer, array $filters): LengthAwarePaginator
    {
        ['scope' => $scope, 'portfolio' => $portfolio] = $this->context($officer, $filters['scope'] ?? null);
        $search = $this->searchPattern($filters['q'] ?? null);

        $page = $this->alertQueue($officer, $filters['queue'] ?? self::ALERTS_AWAITING, $scope, $portfolio, $filters['mission_id'] ?? null)
            ->with([
                'mission',
                'ministry',
                'submittedBy' => fn ($query) => $query->withTrashed(),
                'assignedTo' => fn ($query) => $query->withTrashed(),
            ])
            ->withCount('feedback')
            ->when(isset($filters['intelligence_type']), fn (Builder $query) => $query->where('intelligence_type', $filters['intelligence_type']))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference_number', 'ilike', $search)
                ->orWhere('country', 'ilike', $search)
                ->orWhere('sector', 'ilike', $search)
                ->orWhere('product_category', 'ilike', $search)
                ->orWhere('product_description', 'ilike', $search)))
            ->orderBy('created_at', ($filters['sort'] ?? '-created_at') === 'created_at' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate((int) $filters['per_page'], ['*'], 'page', (int) $filters['page']);

        $inPortfolio = $this->idsInPortfolio($page->getCollection(), $portfolio, fn (Builder $query) => $portfolio->constrainAlerts($query), Alert::class);
        $gate = Gate::forUser($officer);

        return $page->through(fn (Alert $alert): array => [
            'id' => $alert->id,
            'reference_number' => $alert->reference_number,
            'country' => $alert->country,
            'sector' => $alert->sector,
            'product_category' => $alert->product_category,
            'intelligence_type' => $alert->intelligence_type,
            'urgency' => $alert->urgency,
            'status' => $alert->status,
            'excerpt' => $this->excerpt($alert->product_description),
            'mission' => $alert->mission === null ? null : ['id' => $alert->mission->id, 'name' => $alert->mission->name],
            'submitted_by' => $this->presentPerson($alert->submittedBy),
            'assigned_to' => $this->presentPerson($alert->assignedTo),
            'assigned_to_me' => $alert->assigned_to_user_id === $officer->id,
            'feedback_count' => (int) $alert->feedback_count,
            'in_portfolio' => $inPortfolio === null ? null : $inPortfolio->contains($alert->id),
            'created_at' => $alert->created_at,
            'actions' => [
                'acknowledge' => $alert->status !== AlertStatus::Acknowledged && $gate->allows('acknowledge', $alert),
                'feedback' => $gate->allows('postFeedback', $alert),
            ],
        ]);
    }

    /**
     * GET /sdt/hq-workspace/inquiries: inquiries with an "HQ Notified" event
     * (FR-INQ-009), newest notification first by default. Inquirer contact
     * details stay on the inquiry itself (data minimisation).
     *
     * @param  array<string, mixed>  $filters
     */
    public function inquiries(User $officer, array $filters): LengthAwarePaginator
    {
        ['scope' => $scope, 'portfolio' => $portfolio] = $this->context($officer, $filters['scope'] ?? null);
        $search = $this->searchPattern($filters['q'] ?? null);
        $highValue = in_array($filters['high_value'] ?? null, ['1', 'true'], true);
        [$sortColumn, $sortDirection] = match ($filters['sort'] ?? '-notified_at') {
            'notified_at' => ['hq_notified_at', 'asc'],
            '-date_received' => ['date_received', 'desc'],
            'date_received' => ['date_received', 'asc'],
            default => ['hq_notified_at', 'desc'],
        };

        $page = $this->inquiryQueue($officer, $filters['status'] ?? 'open', $scope, $portfolio, $filters['mission_id'] ?? null)
            ->addSelect(['hq_notified_at' => DB::table('inquiry_events')
                ->selectRaw('max(created_at)')
                ->whereColumn('inquiry_events.inquiry_id', 'inquiries.id')
                ->where('inquiry_events.event_type', InquiryEventType::HqNotified->value)])
            ->with(['mission', 'loggedBy' => fn ($query) => $query->withTrashed()])
            ->when($highValue, fn (Builder $query) => $query->where('high_value_flag', true))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference_number', 'ilike', $search)
                ->orWhere('category', 'ilike', $search)
                ->orWhere('inquirer_name', 'ilike', $search)
                ->orWhere('inquirer_organisation', 'ilike', $search)
                ->orWhere('product_or_sector', 'ilike', $search)))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('inquiries.id')
            ->paginate((int) $filters['per_page'], ['*'], 'page', (int) $filters['page']);

        $notifications = InquiryEvent::query()
            ->withoutGlobalScopes()
            ->with(['loggedBy' => fn ($query) => $query->withTrashed()])
            ->whereIn('inquiry_id', $page->getCollection()->pluck('id'))
            ->where('event_type', InquiryEventType::HqNotified->value)
            ->orderByDesc('created_at')
            ->get()
            ->unique('inquiry_id')
            ->keyBy('inquiry_id');

        $inPortfolio = $this->idsInPortfolio($page->getCollection(), $portfolio, fn (Builder $query) => $portfolio->constrainInquiries($query), Inquiry::class);
        $today = Carbon::today();

        return $page->through(function (Inquiry $inquiry) use ($notifications, $inPortfolio, $today): array {
            $notification = $notifications->get($inquiry->id);

            return [
                'id' => $inquiry->id,
                'reference_number' => $inquiry->reference_number,
                'category' => $inquiry->category,
                'sub_type' => $inquiry->sub_type,
                'status' => $inquiry->status,
                'high_value_flag' => (bool) $inquiry->high_value_flag,
                'inquirer_name' => $inquiry->inquirer_name,
                'inquirer_organisation' => $inquiry->inquirer_organisation,
                'product_or_sector' => $inquiry->product_or_sector,
                'excerpt' => $this->excerpt($inquiry->description),
                'date_received' => $inquiry->date_received,
                'days_since_received' => $inquiry->date_received === null ? null : (int) $inquiry->date_received->copy()->startOfDay()->diffInDays($today),
                'mission' => $inquiry->mission === null ? null : ['id' => $inquiry->mission->id, 'name' => $inquiry->mission->name],
                'logged_by' => $this->presentPerson($inquiry->loggedBy),
                'hq_notified' => $notification === null ? null : [
                    'at' => $notification->created_at,
                    'by' => $this->presentPerson($notification->loggedBy),
                    'note' => $notification->note,
                ],
                'in_portfolio' => $inPortfolio === null ? null : $inPortfolio->contains($inquiry->id),
                'created_at' => $inquiry->created_at,
            ];
        });
    }

    /**
     * GET /sdt/hq-workspace/directives: the directives the officer issued,
     * with the DirectiveResource fields, the reasons each needs attention
     * and the officer's allowed actions (DirectiveService::allowedActions()).
     *
     * @param  array<string, mixed>  $filters
     */
    public function directives(User $officer, array $filters): LengthAwarePaginator
    {
        $portfolio = HqPortfolio::forUser($officer);
        $search = $this->searchPattern($filters['q'] ?? null);
        $today = Carbon::today()->toDateString();
        $staleBefore = now()->subDays(DirectiveService::STALE_THRESHOLD_DAYS);

        $page = $this->directiveQueue($officer, $filters['queue'] ?? self::DIRECTIVES_ATTENTION, $filters['mission_id'] ?? null)
            ->with([
                'mission',
                'targetUser' => fn ($query) => $query->withTrashed(),
                'issuedBy' => fn ($query) => $query->withTrashed(),
            ])
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('description', 'ilike', $search)
                ->orWhere('type_category', 'ilike', $search)))
            ->tap(function (Builder $query) use ($filters, $today, $staleBefore): void {
                match ($filters['sort'] ?? 'priority') {
                    'target_completion_date' => $query->orderByRaw('target_completion_date asc nulls last'),
                    '-created_at' => $query->orderByDesc('created_at'),
                    'last_progress_update_at' => $query->orderByRaw('last_progress_update_at asc nulls last'),
                    default => $query
                        ->orderByRaw(
                            'case when status = ? then 0 when status not in (?, ?, ?) and target_completion_date < ? then 1 when status in (?, ?, ?) and last_progress_update_at < ? then 2 else 3 end',
                            [
                                DirectiveStatus::Completed->value,
                                ...DirectiveStatus::finishedValues(),
                                $today,
                                ...DirectiveStatus::openValues(),
                                $staleBefore,
                            ],
                        )
                        ->orderByRaw('target_completion_date asc nulls last')
                        ->orderByRaw('last_progress_update_at asc nulls last'),
                };
            })
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate((int) $filters['per_page'], ['*'], 'page', (int) $filters['page']);

        $inPortfolio = $this->idsInPortfolio($page->getCollection(), $portfolio, fn (Builder $query) => $portfolio->constrainMissionRecords($query), Directive::class);

        return $page->through(fn (Directive $directive): array => [
            ...(new DirectiveResource($directive))->resolve(request()),
            'attention' => $this->attentionReasons($directive),
            'in_portfolio' => $inPortfolio === null ? null : $inPortfolio->contains($directive->id),
            'allowed_actions' => $this->directiveService->allowedActions($directive, $officer),
        ]);
    }

    /**
     * GET /sdt/hq-workspace/reports: submitted reports only (PeriodicReport::
     * visibleTo() never shows an HQ officer a draft), read-only.
     *
     * @param  array<string, mixed>  $filters
     */
    public function reports(User $officer, array $filters): LengthAwarePaginator
    {
        ['scope' => $scope, 'portfolio' => $portfolio] = $this->context($officer, $filters['scope'] ?? null);
        $search = $this->searchPattern($filters['q'] ?? null);

        $page = $this->reportQueue($officer, $scope, $portfolio, $filters['mission_id'] ?? null)
            ->with(['mission', 'authoredBy' => fn ($query) => $query->withTrashed()])
            ->when(isset($filters['period']), fn (Builder $query) => $query->where('reporting_period_label', $filters['period']))
            ->when(isset($filters['timeliness']), fn (Builder $query) => $query->where('is_late', $filters['timeliness'] === 'late'))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reporting_period_label', 'ilike', $search)
                ->orWhereHas('mission', fn (Builder $mission) => $mission
                    ->where('name', 'ilike', $search)
                    ->orWhere('host_country', 'ilike', $search))
                ->orWhereHas('authoredBy', fn (Builder $author) => $author->withTrashed()->where('full_name', 'ilike', $search))))
            ->orderBy('submitted_at', ($filters['sort'] ?? '-submitted_at') === 'submitted_at' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate((int) $filters['per_page'], ['*'], 'page', (int) $filters['page']);

        $inPortfolio = $this->idsInPortfolio($page->getCollection(), $portfolio, fn (Builder $query) => $portfolio->constrainMissionRecords($query), PeriodicReport::class);

        return $page->through(fn (PeriodicReport $report): array => [
            'id' => $report->id,
            'reporting_period_label' => $report->reporting_period_label,
            'period_start_date' => $report->period_start_date?->toDateString(),
            'period_end_date' => $report->period_end_date?->toDateString(),
            'submitted_at' => $report->submitted_at,
            'is_late' => (bool) $report->is_late,
            'mission' => $report->mission === null ? null : $this->presentMission($report->mission),
            'authored_by' => $this->presentPerson($report->authoredBy),
            'in_portfolio' => $inPortfolio === null ? null : $inPortfolio->contains($report->id),
        ]);
    }

    // --- Queues -----------------------------------------------------------

    private function alertQueue(User $officer, string $queue, string $scope, HqPortfolio $portfolio, ?string $missionId): Builder
    {
        return Alert::query()
            ->where('alerts.ministry_id', $officer->ministry_id)
            ->when($missionId !== null, fn (Builder $query) => $query->where('alerts.mission_id', $missionId))
            ->tap(fn (Builder $query) => match ($queue) {
                self::ALERTS_ACKNOWLEDGED => $query
                    ->where('assigned_to_user_id', $officer->id)
                    ->where('status', AlertStatus::Acknowledged->value),
                self::ALERTS_FEED => $query->when($scope === self::SCOPE_PORTFOLIO, fn (Builder $inner) => $portfolio->constrainAlerts($inner)),
                default => $query
                    ->where('assigned_to_user_id', $officer->id)
                    ->where('status', '!=', AlertStatus::Acknowledged->value),
            });
    }

    private function inquiryQueue(User $officer, string $statusGroup, string $scope, HqPortfolio $portfolio, ?string $missionId): Builder
    {
        $statuses = self::INQUIRY_STATUS_GROUPS[$statusGroup] ?? self::INQUIRY_STATUS_GROUPS['open'];

        return Inquiry::query()
            ->where('inquiries.ministry_id', $officer->ministry_id)
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('inquiry_events')
                ->whereColumn('inquiry_events.inquiry_id', 'inquiries.id')
                ->where('inquiry_events.event_type', InquiryEventType::HqNotified->value))
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('inquiries.status', $statuses))
            ->when($missionId !== null, fn (Builder $query) => $query->where('inquiries.mission_id', $missionId))
            ->when($scope === self::SCOPE_PORTFOLIO, fn (Builder $query) => $portfolio->constrainInquiries($query));
    }

    private function directiveQueue(User $officer, string $queue, ?string $missionId): Builder
    {
        return Directive::query()
            ->where('directives.ministry_id', $officer->ministry_id)
            ->where('issued_by_user_id', $officer->id)
            ->when($missionId !== null, fn (Builder $query) => $query->where('directives.mission_id', $missionId))
            ->tap(fn (Builder $query) => match ($queue) {
                self::DIRECTIVES_AWAITING_CLOSURE => $query->where('status', DirectiveStatus::Completed->value),
                self::DIRECTIVES_OPEN => $query->whereIn('status', DirectiveStatus::openValues()),
                self::DIRECTIVES_ALL => $query,
                default => $query->where(fn (Builder $inner) => $inner
                    ->where('status', DirectiveStatus::Completed->value)
                    ->orWhere(fn (Builder $overdue) => $this->overdue($overdue))
                    ->orWhere(fn (Builder $stale) => $this->stale($stale))),
            });
    }

    private function reportQueue(User $officer, string $scope, HqPortfolio $portfolio, ?string $missionId): Builder
    {
        return PeriodicReport::query()
            ->visibleTo($officer)
            ->where('periodic_reports.ministry_id', $officer->ministry_id)
            ->where('periodic_reports.status', PeriodicReportStatus::Submitted->value)
            ->when($missionId !== null, fn (Builder $query) => $query->where('periodic_reports.mission_id', $missionId))
            ->when($scope === self::SCOPE_PORTFOLIO, fn (Builder $query) => $portfolio->constrainMissionRecords($query));
    }

    /**
     * Directive::isOverdue() as SQL: still being worked, target date passed.
     */
    private function overdue(Builder $query): void
    {
        $query->whereNotIn('status', DirectiveStatus::finishedValues())
            ->where('target_completion_date', '<', Carbon::today()->toDateString());
    }

    /**
     * Directive::isStale() as SQL (FR-DIR-010).
     */
    private function stale(Builder $query): void
    {
        $query->whereIn('status', DirectiveStatus::openValues())
            ->where('last_progress_update_at', '<', now()->subDays(DirectiveService::STALE_THRESHOLD_DAYS));
    }

    // --- Presentation -------------------------------------------------------

    /**
     * Why a directive needs its issuer: awaiting_closure (completed by the
     * attache, FR-DIR lifecycle "HQ officer accepts and closes"), overdue,
     * stale, or approaching its target date.
     *
     * @return array<int, string>
     */
    private function attentionReasons(Directive $directive): array
    {
        return array_values(array_filter([
            $directive->status === DirectiveStatus::Completed ? 'awaiting_closure' : null,
            $directive->isOverdue() ? 'overdue' : null,
            $directive->isStale() ? 'stale' : null,
            $directive->dueState() === Directive::DUE_APPROACHING ? 'approaching' : null,
        ]));
    }

    /**
     * The ids on this page that fall inside the portfolio, from the same
     * SQL constraint the scoped lists use (so the flag and the filter can
     * never disagree); null when no portfolio is configured.
     *
     * @param  Collection<int, Model>  $rows
     * @param  class-string<Model>  $modelClass
     * @return Collection<int, string>|null
     */
    private function idsInPortfolio(Collection $rows, HqPortfolio $portfolio, callable $constrain, string $modelClass): ?Collection
    {
        if ($portfolio->isEmpty()) {
            return null;
        }

        if ($rows->isEmpty()) {
            return collect();
        }

        return $modelClass::query()
            ->withoutGlobalScopes()
            ->whereIn((new $modelClass)->qualifyColumn('id'), $rows->pluck('id'))
            ->tap($constrain)
            ->pluck((new $modelClass)->qualifyColumn('id'));
    }

    /**
     * Missions posted to the officer's department, active ones first.
     *
     * @return Collection<int, Mission>
     */
    private function departmentMissions(User $officer): Collection
    {
        return Mission::query()
            ->whereIn('id', MissionMinistryLink::query()->where('ministry_id', $officer->ministry_id)->select('mission_id'))
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();
    }

    /**
     * The most recent quarter with a submitted report in the current scope,
     * with how many of its reports came in on time and late.
     *
     * @return array{label: string, submitted: int, late: int}|null
     */
    private function latestReportPeriod(User $officer, string $scope, HqPortfolio $portfolio, ?string $missionId): ?array
    {
        $latest = $this->reportQueue($officer, $scope, $portfolio, $missionId)
            ->reorder()
            ->orderByDesc('period_start_date')
            ->first(['reporting_period_label']);

        if ($latest === null) {
            return null;
        }

        $reports = $this->reportQueue($officer, $scope, $portfolio, $missionId)
            ->where('reporting_period_label', $latest->reporting_period_label)
            ->get(['id', 'is_late']);

        return [
            'label' => $latest->reporting_period_label,
            'submitted' => $reports->count(),
            'late' => $reports->where('is_late', true)->count(),
        ];
    }

    /**
     * @return array{id: string, name: string, city: string|null, host_country: string|null, active: bool}
     */
    private function presentMission(Mission $mission): array
    {
        return [
            'id' => $mission->id,
            'name' => $mission->name,
            'city' => $mission->city,
            'host_country' => $mission->host_country,
            'active' => (bool) $mission->active,
        ];
    }

    /**
     * @return array{id: string, full_name: string}|null
     */
    private function presentPerson(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'full_name' => $user->full_name];
    }

    private function excerpt(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return Str::limit(Str::squish($text), self::EXCERPT_LENGTH);
    }

    /**
     * An ILIKE pattern that treats the search text literally.
     */
    private function searchPattern(?string $search): ?string
    {
        $search = Str::squish((string) $search);

        return $search === '' ? null : '%'.addcslashes($search, '\\%_').'%';
    }
}
