<?php

namespace App\Services;

use App\Enums\ApprovalRequestStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Models\Alert;
use App\Models\AlertAttachment;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\KpiDefinition;
use App\Models\MasterDataEntry;
use App\Models\Ministry;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\ReferralAttachment;
use App\Models\ReferralOrganisation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * GET /api/v1/admin/dashboard: account health, seat rules, approvals and
 * configuration completeness for the two administrator tiers (ADR-006).
 *
 * A Ministry Administrator is pinned to its own department and sees only
 * administrative data (BR-025): accounts, seats, approval requests,
 * configuration lists and administrative audit entries — never operational
 * records or their counts. The platform block (storage, queue, record
 * volumes, System Administrator seats) is System Administrator only.
 */
class AdministrationDashboardService
{
    /** Active accounts without a sign-in for this many days count as dormant. */
    public const int DORMANT_AFTER_DAYS = 20;

    /** FR-AUTH-002 AC2: activation links are meant to be used within 72 hours. */
    public const int INVITATION_EXPIRY_HOURS = 72;

    /** Consecutive failed sign-ins at which an account is flagged as close to locking (FR-AUTH-009 locks at 5). */
    public const int LOCK_WARNING_ATTEMPTS = 3;

    public const int PENDING_APPROVAL_WARNING_DAYS = 7;

    public const int RECOMMENDED_SYSTEM_ADMINISTRATORS = 2;

    /** TDD-ADR-013: the uploads store is sized for 2 TB. */
    public const int STORAGE_CAPACITY_BYTES = 2_000_000_000_000;

    private const int ACTIVITY_WEEKS = 12;

    private const int LIST_LIMIT = 6;

    /**
     * Master-data categories each department configures (CLAUDE.md Section
     * 8), keyed by the name the dashboard reports them under.
     *
     * @var array<string, string>
     */
    private const array MASTER_DATA_LISTS = [
        'inquiry_categories' => 'inquiry_category',
        'alert_intelligence_types' => 'alert_intelligence_type',
        'directive_types' => 'directive_type',
        'aie_budget_codes' => 'aie_budget_code',
    ];

    public function __construct(private readonly ReportService $reportService) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $actor, ?string $requestedMinistryId = null): array
    {
        $ministryId = AdministrationService::listingMinistryId($actor, $requestedMinistryId);
        $isSystemAdministrator = AdministrationService::isSystemAdministrator($actor);

        $ministries = Ministry::query()
            ->when($ministryId !== null, fn ($query) => $query->whereKey($ministryId))
            ->with('actingPs')
            ->orderBy('name')
            ->get();

        $users = User::withTrashed()
            ->with('role')
            ->when($ministryId !== null, fn ($query) => $query->where('ministry_id', $ministryId))
            ->get(['id', 'full_name', 'role_id', 'ministry_id', 'status', 'last_login_at', 'failed_login_attempts', 'created_at']);

        $accounts = $this->accounts($users);
        $departments = $this->departments($ministries, $users);
        $approvals = $this->approvals($ministryId);
        $platform = $isSystemAdministrator ? $this->platform() : null;

        return [
            'scope' => [
                'type' => $ministryId === null ? 'platform' : 'department',
                'ministry' => $ministryId === null ? null : $ministries->first()?->only(['id', 'name']),
            ],
            'accounts' => $accounts,
            'sign_in_activity' => $this->signInActivity($ministryId),
            'departments' => $departments,
            'approvals' => $approvals,
            'health_checks' => $this->healthChecks($accounts, $departments, $approvals, $platform),
            'recent_activity' => $this->recentActivity($ministryId),
            'platform' => $platform,
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function accounts(Collection $users): array
    {
        $now = now();
        $active = $users->where('status', UserStatus::Active);
        $dormantBefore = $now->copy()->subDays(self::DORMANT_AFTER_DAYS);

        $recency = ['last_7_days' => 0, 'last_30_days' => 0, 'last_90_days' => 0, 'older' => 0, 'never' => 0];

        foreach ($active as $user) {
            $key = match (true) {
                $user->last_login_at === null => 'never',
                $user->last_login_at->gte($now->copy()->subDays(7)) => 'last_7_days',
                $user->last_login_at->gte($now->copy()->subDays(30)) => 'last_30_days',
                $user->last_login_at->gte($now->copy()->subDays(90)) => 'last_90_days',
                default => 'older',
            };

            $recency[$key]++;
        }

        $dormant = $active->filter(fn (User $user): bool => $user->last_login_at !== null
            ? $user->last_login_at->lt($dormantBefore)
            : $user->created_at?->lt($dormantBefore) ?? false);

        return [
            'total' => $users->count(),
            'by_status' => collect(UserStatus::cases())
                ->mapWithKeys(fn (UserStatus $status): array => [$status->value => $users->where('status', $status)->count()])
                ->all(),
            'sign_in_recency' => collect($recency)->map(fn (int $count, string $key): array => ['key' => $key, 'count' => $count])->values()->all(),
            'dormant' => $dormant->count(),
            'never_signed_in' => $recency['never'],
            'stale_invitations' => $users
                ->where('status', UserStatus::ActivationPending)
                ->filter(fn (User $user): bool => $user->created_at?->lt($now->copy()->subHours(self::INVITATION_EXPIRY_HOURS)) ?? false)
                ->count(),
            'close_to_locking' => $active->where('failed_login_attempts', '>=', self::LOCK_WARNING_ATTEMPTS)->count(),
            'by_role' => $users
                ->where('status', '!=', UserStatus::Deactivated)
                ->countBy(fn (User $user): string => $user->role?->name ?? '—')
                ->sortDesc()
                ->map(fn (int $count, string $role): array => ['role' => $role, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    /**
     * Seat rules (BR-026, BR-028), leadership switches, attache posts and
     * configuration completeness, per department in scope.
     *
     * @param  Collection<int, Ministry>  $ministries
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    private function departments(Collection $ministries, Collection $users): array
    {
        $ministryIds = $ministries->pluck('id');
        $holders = $users->where('status', '!=', UserStatus::Deactivated);

        $posts = MissionMinistryLink::query()
            ->whereIn('ministry_id', $ministryIds)
            ->get(['ministry_id', 'active_attache_user_id'])
            ->groupBy('ministry_id');

        $masterData = MasterDataEntry::query()
            ->whereIn('ministry_id', $ministryIds)
            ->whereIn('category', self::MASTER_DATA_LISTS)
            ->where('active', true)
            ->selectRaw('ministry_id, category, count(*) as aggregate')
            ->groupBy('ministry_id', 'category')
            ->toBase()
            ->get()
            ->groupBy('ministry_id');

        $referralOrganisations = $this->countByMinistry(ReferralOrganisation::query()->withoutGlobalScopes()->where('active', true), $ministryIds);
        $kpiDefinitions = $this->countByMinistry(KpiDefinition::query()->withoutGlobalScopes()->where('active', true), $ministryIds);

        return $ministries->map(function (Ministry $ministry) use ($holders, $posts, $masterData, $referralOrganisations, $kpiDefinitions): array {
            $departmentHolders = $holders->where('ministry_id', $ministry->id);
            $principalSecretary = $departmentHolders->first(fn (User $user): bool => $user->role?->name === AdministrationService::MINISTRY_PS);
            $departmentPosts = $posts->get($ministry->id, collect());
            $lists = $masterData->get($ministry->id, collect())->pluck('aggregate', 'category');

            return [
                'id' => $ministry->id,
                'name' => $ministry->name,
                'active' => (bool) $ministry->active,
                'active_accounts' => $departmentHolders->where('status', UserStatus::Active)->count(),
                'ministry_administrators' => [
                    'filled' => $departmentHolders->filter(fn (User $user): bool => AdministrationService::isMinistryAdministrator($user))->count(),
                    'limit' => AdministrationService::MAX_MINISTRY_ADMINISTRATORS,
                ],
                'principal_secretary' => $principalSecretary?->only(['id', 'full_name']),
                'acting_ps' => $ministry->acting_ps_active ? $ministry->actingPs?->only(['id', 'full_name']) : null,
                'designated_deputy_active' => (bool) $ministry->designated_deputy_active,
                'attache_posts' => [
                    'total' => $departmentPosts->count(),
                    'vacant' => $departmentPosts->whereNull('active_attache_user_id')->count(),
                ],
                'configuration' => [
                    ...collect(self::MASTER_DATA_LISTS)->map(fn (string $category): int => (int) ($lists[$category] ?? 0))->all(),
                    'referral_organisations' => $referralOrganisations[$ministry->id] ?? 0,
                    'kpi_definitions' => $kpiDefinitions[$ministry->id] ?? 0,
                    'report_template_sections' => $this->activeTemplateSectionCount($ministry->id),
                ],
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function approvals(?string $ministryId): array
    {
        $requests = ApprovalRequest::query()
            ->withoutGlobalScopes()
            ->with(['ministry', 'requestedBy', 'subjectUser'])
            ->when($ministryId !== null, fn ($query) => $query->where('ministry_id', $ministryId))
            ->where(fn ($query) => $query
                ->where('status', ApprovalRequestStatus::Pending->value)
                ->orWhere('decided_at', '>=', now()->subDays(90)))
            ->get();

        $pending = $requests->where('status', ApprovalRequestStatus::Pending)->sortBy('created_at')->values();

        $decisionHours = $requests
            ->whereNotNull('decided_at')
            ->map(fn (ApprovalRequest $request): float => $request->created_at->diffInMinutes($request->decided_at) / 60)
            ->sort()
            ->values();

        return [
            'pending' => $pending->count(),
            'overdue' => $pending->filter(fn (ApprovalRequest $request): bool => $request->created_at->lt(now()->subDays(self::PENDING_APPROVAL_WARNING_DAYS)))->count(),
            'decided_last_90_days' => $decisionHours->count(),
            'median_decision_hours' => $decisionHours->isEmpty() ? null : round((float) $decisionHours->median(), 1),
            'items' => $pending->take(self::LIST_LIMIT)->map(fn (ApprovalRequest $request): array => [
                'id' => $request->id,
                'type' => $request->type->value,
                'ministry_name' => $request->ministry?->name,
                'requested_by' => $request->requestedBy?->full_name,
                'subject' => $request->subjectUser?->full_name ?? ($request->payload['full_name'] ?? $request->payload['incoming_full_name'] ?? null),
                'created_at' => $request->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Weekly distinct users signing in and failed sign-in attempts, from the
     * audit trail (FR-AUDIT-001). Sign-in rows are User entries, which a
     * Ministry Administrator's audit view already includes (FR-AUDIT-006).
     *
     * @return array<int, array{week_start: string, active_users: int, failed_attempts: int}>
     */
    private function signInActivity(?string $ministryId): array
    {
        $firstWeek = now()->startOfWeek()->subWeeks(self::ACTIVITY_WEEKS - 1)->startOfDay();

        $rows = AuditLog::query()
            ->whereIn('action', ['user.login.succeeded', 'user.login.failed', 'user.login.failed_account_locked'])
            ->where('created_at', '>=', $firstWeek)
            ->when($ministryId !== null, fn ($query) => $query->where('ministry_id', $ministryId))
            ->selectRaw("date_trunc('week', created_at)::date as week_start, action, count(*) as events, count(distinct user_id) as people")
            ->groupBy('week_start', 'action')
            ->toBase()
            ->get()
            ->groupBy(fn (object $row): string => Carbon::parse($row->week_start)->toDateString());

        return collect(range(0, self::ACTIVITY_WEEKS - 1))
            ->map(function (int $offset) use ($firstWeek, $rows): array {
                $week = $firstWeek->copy()->addWeeks($offset)->toDateString();
                $weekRows = $rows->get($week, collect());

                return [
                    'week_start' => $week,
                    'active_users' => (int) ($weekRows->firstWhere('action', 'user.login.succeeded')?->people ?? 0),
                    'failed_attempts' => (int) $weekRows->where('action', '!=', 'user.login.succeeded')->sum('events'),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(?string $ministryId): array
    {
        return AuditLog::query()
            ->with('user')
            ->whereIn('affected_entity_type', AuditLogController::ADMINISTRATIVE_ENTITY_TYPES)
            // Sign-in bookkeeping, actor-less observer rows and people updating
            // their own account (last_login_at, preferences, photo) would crowd
            // out the changes an administrator actually made.
            ->where('action', 'not like', 'user.login.%')
            ->where('action', '!=', 'user.logout')
            ->whereNotNull('user_id')
            ->whereNot(fn ($query) => $query
                ->where('affected_entity_type', User::class)
                ->whereColumn('affected_entity_id', 'user_id'))
            ->when($ministryId !== null, fn ($query) => $query->where('ministry_id', $ministryId))
            ->latest('created_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->user?->full_name,
                'entity_type' => class_basename($log->affected_entity_type),
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * System Administrator only: platform capacity and background-queue
     * health (TDD-ADR-010, TDD-ADR-013) plus record volumes per engine.
     *
     * @return array<string, mixed>
     */
    private function platform(): array
    {
        $storageBytes = (int) AlertAttachment::query()->withoutGlobalScopes()->sum('file_size_bytes')
            + (int) ReferralAttachment::query()->withoutGlobalScopes()->sum('file_size_bytes');

        return [
            'system_administrators' => User::query()
                ->where('status', UserStatus::Active)
                ->whereHas('role', fn ($query) => $query->where('name', AdministrationService::SYSTEM_ADMINISTRATOR))
                ->count(),
            'storage' => [
                'used_bytes' => $storageBytes,
                'capacity_bytes' => self::STORAGE_CAPACITY_BYTES,
                'files' => AlertAttachment::query()->withoutGlobalScopes()->count() + ReferralAttachment::query()->withoutGlobalScopes()->count(),
            ],
            'queue' => [
                'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            ],
            'records' => [
                'alerts' => Alert::query()->withoutGlobalScopes()->count(),
                'inquiries' => Inquiry::query()->withoutGlobalScopes()->count(),
                'directives' => Directive::query()->withoutGlobalScopes()->count(),
                'periodic_reports' => PeriodicReport::query()->withoutGlobalScopes()->count(),
            ],
        ];
    }

    /**
     * Pass/warn/fail checks derived from the figures above. The dashboard
     * words each one from its key; `value` carries the number the wording
     * needs.
     *
     * @param  array<string, mixed>  $accounts
     * @param  array<int, array<string, mixed>>  $departments
     * @param  array<string, mixed>  $approvals
     * @param  array<string, mixed>|null  $platform
     * @return array<int, array{key: string, status: string, value: int|float, detail?: array<int, string>}>
     */
    private function healthChecks(array $accounts, array $departments, array $approvals, ?array $platform): array
    {
        $activeDepartments = collect($departments)->where('active', true);
        $activeAccounts = $accounts['by_status'][UserStatus::Active->value];
        $dormantShare = $activeAccounts > 0 ? round($accounts['dormant'] / $activeAccounts * 100, 1) : 0.0;

        $emptyLists = $activeDepartments
            ->flatMap(fn (array $department): array => collect($department['configuration'])->filter(fn (int $count): bool => $count === 0)->keys()->all())
            ->unique()
            ->values();

        $checks = [];

        if ($platform !== null) {
            $checks[] = [
                'key' => 'system_administrators',
                'status' => match (true) {
                    $platform['system_administrators'] >= self::RECOMMENDED_SYSTEM_ADMINISTRATORS => 'pass',
                    $platform['system_administrators'] === 1 => 'warn',
                    default => 'fail',
                },
                'value' => $platform['system_administrators'],
            ];
        }

        $checks[] = $this->countCheck('principal_secretaries', $activeDepartments->whereNull('principal_secretary')->count());
        $overSeatLimit = $activeDepartments->filter(fn (array $department): bool => $department['ministry_administrators']['filled'] > $department['ministry_administrators']['limit'])->count();
        $withoutAdministrator = $activeDepartments->where('ministry_administrators.filled', 0)->count();
        $checks[] = [
            'key' => 'ministry_administrators',
            'status' => match (true) {
                $overSeatLimit > 0 => 'fail',
                $withoutAdministrator > 0 => 'warn',
                default => 'pass',
            },
            'value' => $overSeatLimit > 0 ? $overSeatLimit : $withoutAdministrator,
            'detail' => [$overSeatLimit > 0 ? 'over_limit' : 'unfilled'],
        ];
        $checks[] = $this->countCheck('locked_accounts', $accounts['by_status'][UserStatus::Locked->value]);
        $checks[] = $this->countCheck('stale_invitations', $accounts['stale_invitations']);
        $checks[] = [
            'key' => 'dormant_accounts',
            'status' => match (true) {
                $dormantShare < 25 => 'pass',
                $dormantShare < 50 => 'warn',
                default => 'fail',
            },
            'value' => $dormantShare,
        ];
        $checks[] = $this->countCheck('vacant_attache_posts', (int) $activeDepartments->sum('attache_posts.vacant'));
        $checks[] = $this->countCheck('overdue_approvals', $approvals['overdue']);
        $checks[] = [...$this->countCheck('configuration_gaps', $emptyLists->count()), 'detail' => $emptyLists->all()];

        if ($platform !== null) {
            $checks[] = [
                'key' => 'failed_jobs',
                'status' => $platform['queue']['failed'] === 0 ? 'pass' : 'fail',
                'value' => $platform['queue']['failed'],
            ];
        }

        return $checks;
    }

    /**
     * @return array{key: string, status: string, value: int}
     */
    private function countCheck(string $key, int $count): array
    {
        return ['key' => $key, 'status' => $count === 0 ? 'pass' : 'warn', 'value' => $count];
    }

    /**
     * @param  Collection<int, string>  $ministryIds
     * @return array<string, int>
     */
    private function countByMinistry(Builder $query, Collection $ministryIds): array
    {
        return $query
            ->whereIn('ministry_id', $ministryIds)
            ->selectRaw('ministry_id, count(*) as aggregate')
            ->groupBy('ministry_id')
            ->toBase()
            ->pluck('aggregate', 'ministry_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function activeTemplateSectionCount(string $ministryId): int
    {
        try {
            return $this->reportService->getActiveTemplate($ministryId)->count();
        } catch (InvalidArgumentException) {
            return 0;
        }
    }
}
