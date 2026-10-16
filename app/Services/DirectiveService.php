<?php

namespace App\Services;

use App\Enums\DirectiveStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Directive;
use App\Models\DirectiveNote;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / URD Section 10.5 (FR-DIR-002 to 014): business
 * logic for the Directive and Tasking Engine. Directives\DirectiveController
 * stays thin and delegates every mutation here.
 *
 * BR-018: a directive's target mission and target attache are immutable
 * once issued. The schema has no issued_at column (CLAUDE.md Section 6);
 * issueDirective() creates the draft row and issues it in one transaction,
 * so created_at marks issuance and last_progress_update_at starts the
 * FR-DIR-010 staleness clock at issue time.
 *
 * last_progress_update_at is bumped by every status transition and by a
 * note written by the TARGET attache (FR-DIR-008 progress). Issuer
 * follow-up notes (FR-DIR-011) and revisions do not count as progress,
 * otherwise an issuer chasing a silent attache would clear the stale flag.
 */
class DirectiveService
{
    /**
     * CLAUDE.md Section 8 Stale Directive Threshold (FR-DIR-010).
     */
    public const int STALE_THRESHOLD_DAYS = 14;

    /**
     * FR-DIR-003 "Approaching" grouping: due today or within this many days.
     */
    public const int APPROACHING_WINDOW_DAYS = 7;

    /**
     * CLAUDE.md Section 8 Reminder Schedule (FR-DIR-004): days before the
     * target completion date.
     *
     * @var array<int, int>
     */
    public const array REMINDER_LEAD_DAYS = [7, 3];

    /**
     * Keyed by target status, valued by the statuses it may be reached
     * from (URD Section 10.5 lifecycle diagram). FR-DIR-006 AC1 allows an
     * attache to start work straight from issued.
     *
     * @var array<string, array<int, string>>
     */
    public const array ALLOWED_TRANSITIONS = [
        'acknowledged' => ['issued'],
        'in_progress' => ['issued', 'acknowledged'],
        'completed' => ['in_progress'],
        'cancelled' => ['issued', 'acknowledged', 'in_progress'],
        'closed' => ['completed'],
    ];

    /**
     * Statuses whose transition requires a non-blank note: the FR-DIR-007
     * completion summary, and the issuer's withdrawal reason.
     *
     * @var array<int, string>
     */
    public const array NOTE_REQUIRED_STATUSES = ['completed', 'cancelled'];

    /**
     * Lifecycle order, used to break same-second ties in the status history.
     *
     * @var array<string, int>
     */
    private const array LIFECYCLE_RANK = [
        'issued' => 0,
        'acknowledged' => 1,
        'in_progress' => 2,
        'completed' => 3,
        'cancelled' => 3,
        'closed' => 4,
    ];

    public function __construct(private readonly NotificationService $notificationService) {}

    /**
     * @param  array<string, mixed>  $data  Already validated by StoreDirectiveRequest.
     */
    public function issueDirective(array $data, User $issuer): Directive
    {
        return DB::transaction(function () use ($data, $issuer): Directive {
            $directive = Directive::create([
                'ministry_id' => $issuer->ministry_id,
                'mission_id' => $data['mission_id'],
                'target_user_id' => $data['target_user_id'],
                'issued_by_user_id' => $issuer->id,
                'type_category' => $data['type_category'] ?? null,
                'description' => $data['description'],
                'target_completion_date' => $data['target_completion_date'] ?? null,
                'status' => DirectiveStatus::Draft,
            ]);

            $directive->forceFill([
                'status' => DirectiveStatus::Issued,
                'last_progress_update_at' => now(),
            ])->save();

            $this->notifyTargetAttache($directive);

            return $directive;
        });
    }

    public function canTransition(Directive $directive, string $newStatus): bool
    {
        $allowedSources = self::ALLOWED_TRANSITIONS[$newStatus] ?? null;

        return $allowedSources !== null && in_array($directive->status?->value, $allowedSources, true);
    }

    /**
     * FR-DIR-006, 007, 014. Authorization (who may request which target) is
     * DirectivePolicy::transitionStatus(); this enforces the state machine.
     *
     * @throws InvalidArgumentException If $newStatus is not a configured
     *                                  transition target, the current status
     *                                  may not move to it, or a required
     *                                  note (completion summary / withdrawal
     *                                  reason) is blank.
     */
    public function transitionStatus(Directive $directive, string $newStatus, User $actor, ?string $note): void
    {
        if (! array_key_exists($newStatus, self::ALLOWED_TRANSITIONS)) {
            throw new InvalidArgumentException("[{$newStatus}] is not a configured directive status transition.");
        }

        $note = trim((string) $note);

        if ($note === '' && $newStatus === DirectiveStatus::Completed->value) {
            throw new InvalidArgumentException('A completion summary is required to complete a directive (FR-DIR-007).');
        }

        if ($note === '' && $newStatus === DirectiveStatus::Cancelled->value) {
            throw new InvalidArgumentException('A reason is required to withdraw (cancel) a directive.');
        }

        $isCompleting = $newStatus === DirectiveStatus::Completed->value;

        DB::transaction(function () use ($directive, $newStatus, $actor, $note, $isCompleting): void {
            $this->lockAndRefresh($directive);

            if (! $this->canTransition($directive, $newStatus)) {
                $from = DirectiveStatus::from($directive->status->value)->label();
                $to = DirectiveStatus::from($newStatus)->label();

                throw new InvalidArgumentException("A directive that is {$from} cannot be moved to {$to}.");
            }

            $directive->forceFill([
                'status' => $newStatus,
                'last_progress_update_at' => now(),
                ...($isCompleting ? ['completion_summary' => $note] : []),
            ])->save();

            if ($note !== '') {
                DirectiveNote::create([
                    'directive_id' => $directive->id,
                    'authored_by_user_id' => $actor->id,
                    'content' => $note,
                ]);
            }

            $this->notifyStatusChange($directive, DirectiveStatus::from($newStatus), $actor, $note);
        });
    }

    /**
     * FR-DIR-008 / FR-DIR-011. A note by the target attache is progress:
     * it resets the stale clock and notifies the issuer. A note by the
     * issuer is a follow-up: it notifies the target and leaves the clock.
     */
    public function addNote(Directive $directive, string $content, User $actor): DirectiveNote
    {
        return DB::transaction(function () use ($directive, $content, $actor): DirectiveNote {
            $note = DirectiveNote::create([
                'directive_id' => $directive->id,
                'authored_by_user_id' => $actor->id,
                'content' => $content,
            ]);

            $excerpt = $this->excerpt($directive->description);

            if ($actor->id === $directive->target_user_id) {
                $directive->forceFill(['last_progress_update_at' => now()])->save();

                $this->notifyUser(
                    $directive->issuedBy,
                    'directive_note_added',
                    "{$actor->full_name} added a progress note to directive \"{$excerpt}\".",
                    $directive,
                );
            } elseif ($actor->id === $directive->issued_by_user_id) {
                $this->notifyUser(
                    $directive->targetUser,
                    'directive_follow_up',
                    "{$actor->full_name} added a follow-up note to directive \"{$excerpt}\".",
                    $directive,
                );
            }

            return $note;
        });
    }

    /**
     * FR-DIR-003 "optional and revisable": the issuer may change the target
     * completion date (or clear it to "No Date Set") and the type category
     * while the directive is open. The target, mission and description
     * never change (BR-018 / FR-DIR-013). Each real change is recorded as a
     * note by the actor and the target attache is notified.
     *
     * @param  array<string, mixed>  $data  Already validated by ReviseDirectiveRequest.
     *
     * @throws InvalidArgumentException If the directive is no longer open.
     */
    public function reviseDirective(Directive $directive, array $data, User $actor): Directive
    {
        return DB::transaction(function () use ($directive, $data, $actor): Directive {
            $this->lockAndRefresh($directive);

            if (! $directive->isOpen()) {
                throw new InvalidArgumentException('Only an open directive can be revised.');
            }

            return $this->applyRevision($directive, $data, $actor);
        });
    }

    /**
     * Re-reads the directive's row under FOR UPDATE and syncs the given
     * instance to it, so the state-machine checks run against the committed
     * status: two concurrent requests (the attache completing while the
     * issuer withdraws) serialise, and the second sees the first's result
     * instead of overwriting it from a stale model. The id was already
     * resolved and authorised through the ministry-scoped route binding, so
     * the re-read by primary key needs no scope (and must also work from a
     * console context that has none).
     */
    private function lockAndRefresh(Directive $directive): void
    {
        $locked = Directive::query()->withoutGlobalScopes()->whereKey($directive->id)->lockForUpdate()->firstOrFail();

        $directive->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyRevision(Directive $directive, array $data, User $actor): Directive
    {
        $changes = [];
        $messages = [];

        if (array_key_exists('target_completion_date', $data)) {
            $current = $directive->target_completion_date?->toDateString();
            $requested = $data['target_completion_date'] === null ? null : Carbon::parse($data['target_completion_date'])->toDateString();

            if ($current !== $requested) {
                $changes['target_completion_date'] = $requested;
                $to = $requested === null ? 'no date set' : $this->formatDate($requested);
                $from = $current === null ? 'no date set' : $this->formatDate($current);
                $messages[] = "Target completion date changed from {$from} to {$to}.";
            }
        }

        if (array_key_exists('type_category', $data)) {
            $requested = $data['type_category'] === null ? null : trim((string) $data['type_category']);
            $requested = $requested === '' ? null : $requested;

            if ($directive->type_category !== $requested) {
                $changes['type_category'] = $requested;
                $to = $requested === null ? 'none' : "\"{$requested}\"";
                $from = $directive->type_category === null ? 'none' : "\"{$directive->type_category}\"";
                $messages[] = "Directive type changed from {$from} to {$to}.";
            }
        }

        if ($changes === []) {
            return $directive;
        }

        $directive->forceFill($changes)->save();

        $summary = implode(' ', $messages);

        DirectiveNote::create([
            'directive_id' => $directive->id,
            'authored_by_user_id' => $actor->id,
            'content' => $summary,
        ]);

        $this->notifyUser(
            $directive->targetUser,
            'directive_revised',
            "{$actor->full_name} revised directive \"{$this->excerpt($directive->description)}\". {$summary}",
            $directive,
        );

        return $directive;
    }

    /**
     * The statuses $user may move $directive into right now: the policy
     * (who) and the state machine (from where) together, so the UI shows
     * exactly what the API accepts.
     *
     * @return array{transitions: array<int, string>, add_note: bool, revise: bool}
     */
    public function allowedActions(Directive $directive, ?User $user): array
    {
        if ($user === null) {
            return ['transitions' => [], 'add_note' => false, 'revise' => false];
        }

        $gate = Gate::forUser($user);

        $transitions = collect(array_keys(self::ALLOWED_TRANSITIONS))
            ->filter(fn (string $status): bool => $this->canTransition($directive, $status)
                && $gate->allows('transitionStatus', [$directive, $status]))
            ->values()
            ->all();

        return [
            'transitions' => $transitions,
            'add_note' => $gate->allows('addNote', $directive),
            'revise' => $directive->isOpen() && $gate->allows('revise', $directive),
        ];
    }

    /**
     * Dated workflow steps, rebuilt from the audit trail (ModelObserver
     * writes a directive.updated row whose changes.after carries the new
     * status — changes.before/after is the {before, after} shape every
     * create/update/delete audit entry now carries, not a flat diff).
     * Falls back to a single issued step for rows that predate auditing.
     *
     * @return array<int, array{status: string, at: mixed, by: array{id: string, full_name: string}|null}>
     */
    public function statusHistory(Directive $directive): array
    {
        $history = $this->statusChangeLogs()
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->where('affected_entity_id', $directive->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'status' => (string) ($log->changes['after']['status'] ?? ''),
                'at' => $log->created_at,
                'by' => $log->user === null ? null : ['id' => $log->user->id, 'full_name' => $log->user->full_name],
            ])
            ->filter(fn (array $entry): bool => array_key_exists($entry['status'], self::LIFECYCLE_RANK))
            ->sort(function (array $a, array $b): int {
                return [$a['at']?->getTimestamp(), self::LIFECYCLE_RANK[$a['status']]]
                    <=> [$b['at']?->getTimestamp(), self::LIFECYCLE_RANK[$b['status']]];
            })
            ->values();

        if ($history->isNotEmpty() && $history->first()['status'] === DirectiveStatus::Issued->value) {
            return $history->all();
        }

        $directive->loadMissing('issuedBy');

        $issued = [
            'status' => DirectiveStatus::Issued->value,
            'at' => $directive->created_at,
            'by' => $directive->issuedBy === null ? null : ['id' => $directive->issuedBy->id, 'full_name' => $directive->issuedBy->full_name],
        ];

        return $history->prepend($issued)->values()->all();
    }

    /**
     * GET /directives/assignees: the missions linked to the actor's ministry
     * (active only) and the active Ministry Attaches of that ministry posted
     * at each. A mission with no attache is still listed, with no attaches.
     *
     * @return array<int, array{id: string, name: string, city: string, host_country: string, attaches: array<int, array{id: string, full_name: string}>}>
     */
    public function listAssignees(User $actor): array
    {
        if ($actor->ministry_id === null) {
            return [];
        }

        $missions = Mission::query()
            ->where('active', true)
            ->whereIn('id', MissionMinistryLink::query()->where('ministry_id', $actor->ministry_id)->select('mission_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'host_country']);

        $attachesByMission = $this->eligibleAttaches($actor->ministry_id)
            ->whereIn('mission_id', $missions->pluck('id'))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'mission_id'])
            ->groupBy('mission_id');

        return $missions->map(fn (Mission $mission): array => [
            'id' => $mission->id,
            'name' => $mission->name,
            'city' => $mission->city,
            'host_country' => $mission->host_country,
            'attaches' => $attachesByMission->get($mission->id, collect())
                ->map(fn (User $user): array => ['id' => $user->id, 'full_name' => $user->full_name])
                ->values()
                ->all(),
        ])->all();
    }

    /**
     * Active Ministry Attaches of a ministry: the only valid directive
     * targets (StoreDirectiveRequest reuses this query).
     *
     * @return Builder<User>
     */
    public function eligibleAttaches(string $ministryId): Builder
    {
        return User::query()
            ->where('ministry_id', $ministryId)
            ->where('status', UserStatus::Active->value)
            ->whereHas('role', fn ($query) => $query->where('name', 'Ministry Attache'));
    }

    /**
     * FR-DIR-010: notify the target attache AND the issuer of every open
     * directive with no progress for more than the threshold, once per
     * stale episode (a new progress update starts a new episode). Runs
     * unscoped: a scheduled command has no user to bind ministry.scope from.
     *
     * @return int The number of notifications written.
     */
    public function flagStaleDirectives(): int
    {
        $sent = 0;

        Directive::query()
            ->withoutGlobalScopes()
            ->whereIn('status', DirectiveStatus::openValues())
            ->where('last_progress_update_at', '<', now()->subDays(self::STALE_THRESHOLD_DAYS))
            ->with(['targetUser', 'issuedBy', 'mission'])
            ->each(function (Directive $directive) use (&$sent): void {
                $excerpt = $this->excerpt($directive->description);
                $days = self::STALE_THRESHOLD_DAYS;

                $messages = [
                    [$directive->targetUser, "Directive \"{$excerpt}\" has had no progress update in over {$days} days. Please add a progress note."],
                    [$directive->issuedBy, "Directive \"{$excerpt}\" issued to {$directive->targetUser?->full_name} ({$directive->mission?->name}) has had no progress update in over {$days} days."],
                ];

                foreach ($messages as [$recipient, $message]) {
                    if ($recipient === null || $this->alreadyNotified($recipient, 'directive_stale', $directive, $directive->last_progress_update_at)) {
                        continue;
                    }

                    if ($this->notifyUser($recipient, 'directive_stale', $message, $directive)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /**
     * FR-DIR-004: remind the target attache 7 and 3 days before the target
     * completion date, for open directives only. Safe to re-run on the same
     * day: a reminder already written today is not repeated.
     *
     * @return int The number of reminders written.
     */
    public function sendDueReminders(): int
    {
        $sent = 0;
        $today = Carbon::today();
        $dueDates = collect(self::REMINDER_LEAD_DAYS)->map(fn (int $days): string => $today->copy()->addDays($days)->toDateString());

        Directive::query()
            ->withoutGlobalScopes()
            ->whereIn('status', DirectiveStatus::openValues())
            ->whereIn('target_completion_date', $dueDates->all())
            ->with('targetUser')
            ->each(function (Directive $directive) use (&$sent, $today): void {
                $recipient = $directive->targetUser;

                if ($recipient === null || $this->alreadyNotified($recipient, 'directive_due_reminder', $directive, $today)) {
                    return;
                }

                $days = $directive->daysUntilDue();
                $due = $this->formatDate($directive->target_completion_date->toDateString());

                $notified = $this->notifyUser(
                    $recipient,
                    'directive_due_reminder',
                    "Directive \"{$this->excerpt($directive->description)}\" is due in {$days} days on {$due}.",
                    $directive,
                );

                if ($notified) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * FR-DIR-012: counts, percentages and breakdowns for a ministry's
     * directives. Every original key is kept for existing callers
     * (DashboardService, the SDT overview/compliance screens); `completed`
     * counts completed AND closed. The due-state counts (overdue,
     * approaching, no_target_date, on_track) partition the directives that
     * are still being worked, using Directive::dueState().
     *
     * Filters (all optional, invalid values ignored): date_from/date_to on
     * the issue date, mission_id, issued_by_user_id.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getSummary(string $ministryId, array $filters = []): array
    {
        $filters = $this->sanitiseSummaryFilters($filters);

        $all = Directive::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->with(['mission', 'issuedBy' => fn ($query) => $query->withTrashed()])
            ->get();

        $directives = $all
            ->when($filters['date_from'] !== null, fn (Collection $c) => $c->filter(fn (Directive $d): bool => $d->created_at->toDateString() >= $filters['date_from']))
            ->when($filters['date_to'] !== null, fn (Collection $c) => $c->filter(fn (Directive $d): bool => $d->created_at->toDateString() <= $filters['date_to']))
            ->when($filters['mission_id'] !== null, fn (Collection $c) => $c->where('mission_id', $filters['mission_id']))
            ->when($filters['issued_by_user_id'] !== null, fn (Collection $c) => $c->where('issued_by_user_id', $filters['issued_by_user_id']))
            ->values();

        $counts = $this->countDirectives($directives);
        $total = $counts['total'];

        return [
            ...$counts,
            'percentages' => [
                'completed' => $this->percentageOf($counts['completed'], $total),
                'in_progress' => $this->percentageOf($counts['in_progress'], $total),
                'overdue' => $this->percentageOf($counts['overdue'], $total),
                'no_target_date' => $this->percentageOf($counts['no_target_date'], $total),
            ],
            'by_mission' => $directives->groupBy('mission_id')
                ->map(function (Collection $group): array {
                    $counts = $this->countDirectives($group);

                    return [
                        'mission_id' => $group->first()->mission_id,
                        'mission_name' => $group->first()->mission?->name,
                        'total' => $counts['total'],
                        'completed' => $counts['completed'],
                        'in_progress' => $counts['in_progress'],
                        'overdue' => $counts['overdue'],
                        'completion_rate' => $this->percentageOf($counts['completed'], $counts['total']),
                    ];
                })
                ->sortBy(fn (array $row): string => Str::lower((string) $row['mission_name']))
                ->values()
                ->all(),
            'by_issuer' => $directives->groupBy('issued_by_user_id')
                ->map(function (Collection $group): array {
                    $counts = $this->countDirectives($group);

                    return [
                        'user_id' => $group->first()->issued_by_user_id,
                        'full_name' => $group->first()->issuedBy?->full_name,
                        'total' => $counts['total'],
                        'completed' => $counts['completed'],
                        'overdue' => $counts['overdue'],
                    ];
                })
                ->sortBy(fn (array $row): string => Str::lower((string) $row['full_name']))
                ->values()
                ->all(),
            'filter_options' => [
                'missions' => $all->pluck('mission')->filter()->unique('id')
                    ->sortBy(fn (Mission $mission): string => Str::lower($mission->name))
                    ->map(fn (Mission $mission): array => ['id' => $mission->id, 'name' => $mission->name])
                    ->values()
                    ->all(),
                'issuers' => $all->pluck('issuedBy')->filter()->unique('id')
                    ->sortBy(fn (User $user): string => Str::lower($user->full_name))
                    ->map(fn (User $user): array => ['id' => $user->id, 'full_name' => $user->full_name])
                    ->values()
                    ->all(),
            ],
            'filters' => $filters,
        ];
    }

    /**
     * The directive oversight board behind GET /sdt/directives/overview:
     * FR-SDT-001 (the national directive summary on the PS console),
     * FR-SDT-008/010 (the directors' read-only oversight of every mission's
     * directives), FR-DIR-003, FR-DIR-010 and FR-DIR-012. Filters (both
     * optional, invalid values ignored): mission_id, issued_by_user_id.
     * Every figure comes from Directive's derived flags, so it matches
     * GET /directives with the same filters, which the board links into.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getOverview(string $ministryId, array $filters, User $viewer): array
    {
        $filters = $this->sanitiseSummaryFilters(['mission_id' => $filters['mission_id'] ?? null, 'issued_by_user_id' => $filters['issued_by_user_id'] ?? null]);
        $directives = $this->directivesFor($ministryId, $filters);
        $mine = $directives->where('issued_by_user_id', $viewer->id);

        return [
            'summary' => $this->getSummary($ministryId, $filters),
            'attention' => $this->attentionCounts($directives),
            'missions' => $this->missionBoard($ministryId, $directives, $filters['mission_id']),
            'issuers' => $this->issuerBoard($directives),
            'my_follow_through' => $mine->isEmpty() ? null : $this->attentionCounts($mine),
            'recent_activity' => $this->recentActivity($ministryId, $filters),
            'filters' => ['mission_id' => $filters['mission_id'], 'issued_by_user_id' => $filters['issued_by_user_id']],
        ];
    }

    /**
     * FR-SDT-009 (Session 27 numbering): the per-mission completion board,
     * every mission posted to the department included, with the ministry
     * summary. The same rows as the overview's mission board.
     *
     * @return array<string, mixed>
     */
    public function getComplianceDashboard(string $ministryId): array
    {
        return [
            'missions' => $this->missionBoard($ministryId, $this->directivesFor($ministryId, ['mission_id' => null, 'issued_by_user_id' => null]), null),
            'summary' => $this->getSummary($ministryId),
        ];
    }

    /**
     * @param  array{mission_id: string|null, issued_by_user_id: string|null}  $filters
     * @return Collection<int, Directive>
     */
    private function directivesFor(string $ministryId, array $filters): Collection
    {
        return Directive::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->when($filters['mission_id'] !== null, fn (Builder $query) => $query->where('mission_id', $filters['mission_id']))
            ->when($filters['issued_by_user_id'] !== null, fn (Builder $query) => $query->where('issued_by_user_id', $filters['issued_by_user_id']))
            ->with(['mission', 'issuedBy' => fn ($query) => $query->withTrashed()])
            ->get();
    }

    /**
     * What needs someone's attention now: the open directives and, among
     * them, the overdue, approaching, stale and undated ones, plus the
     * completed directives waiting for their issuer to accept and close
     * them (the review step of the URD lifecycle).
     *
     * @param  Collection<int, Directive>  $directives
     * @return array{open: int, overdue: int, approaching: int, stale: int, no_date: int, awaiting_closure: int}
     */
    private function attentionCounts(Collection $directives): array
    {
        return [
            'open' => $directives->filter(fn (Directive $directive): bool => $directive->isOpen())->count(),
            'overdue' => $directives->filter(fn (Directive $directive): bool => $directive->isOverdue())->count(),
            'approaching' => $directives->filter(fn (Directive $directive): bool => $directive->dueState() === Directive::DUE_APPROACHING)->count(),
            'stale' => $directives->filter(fn (Directive $directive): bool => $directive->isStale())->count(),
            'no_date' => $directives->filter(fn (Directive $directive): bool => $directive->dueState() === Directive::DUE_NO_DATE)->count(),
            'awaiting_closure' => $directives->filter(fn (Directive $directive): bool => $directive->status === DirectiveStatus::Completed)->count(),
        ];
    }

    /**
     * One row per mission posted to the department (a mission with no
     * directive yet included, as "idle"), worst first: "attention" (overdue
     * or stale work), "watch" (due within the approaching window, or
     * completed work waiting to be closed), "on_track", "idle".
     *
     * @param  Collection<int, Directive>  $directives
     * @return array<int, array<string, mixed>>
     */
    private function missionBoard(string $ministryId, Collection $directives, ?string $missionFilter): array
    {
        $byMission = $directives->groupBy('mission_id');

        $links = MissionMinistryLink::query()
            ->where('ministry_id', $ministryId)
            ->when($missionFilter !== null, fn (Builder $query) => $query->where('mission_id', $missionFilter))
            ->with(['mission', 'activeAttache' => fn ($query) => $query->withTrashed()])
            ->get()
            ->filter(fn (MissionMinistryLink $link): bool => $link->mission !== null)
            ->unique('mission_id')
            ->keyBy('mission_id');

        $missions = $links->map(fn (MissionMinistryLink $link): array => [$link->mission, $link->activeAttache]);

        foreach ($byMission as $missionId => $group) {
            if (! $missions->has($missionId) && $group->first()->mission !== null) {
                $missions->put($missionId, [$group->first()->mission, null]);
            }
        }

        $healthRank = ['attention' => 0, 'watch' => 1, 'on_track' => 2, 'idle' => 3];

        return $missions
            ->map(function (array $entry, string $missionId) use ($byMission): array {
                [$mission, $attache] = $entry;
                $group = $byMission->get($missionId, collect());
                $counts = $this->countDirectives($group);
                $attention = $this->attentionCounts($group);
                $lastProgress = $group->pluck('last_progress_update_at')->filter()->sortDesc()->first();

                return [
                    'mission_id' => $mission->id,
                    'mission_name' => $mission->name,
                    'host_country' => $mission->host_country,
                    'active' => (bool) $mission->active,
                    'attache' => $attache === null ? null : ['id' => $attache->id, 'full_name' => $attache->full_name],
                    'total' => $counts['total'],
                    'completed' => $counts['completed'],
                    'completion_rate' => $this->percentageOf($counts['completed'], $counts['total']),
                    ...$attention,
                    'last_progress_at' => $lastProgress,
                    'health' => match (true) {
                        $attention['overdue'] > 0 || $attention['stale'] > 0 => 'attention',
                        $attention['approaching'] > 0 || $attention['awaiting_closure'] > 0 => 'watch',
                        $attention['open'] > 0 => 'on_track',
                        default => 'idle',
                    },
                ];
            })
            ->sort(fn (array $a, array $b): int => [$healthRank[$a['health']], -$a['overdue'], -$a['stale'], Str::lower($a['mission_name'])]
                <=> [$healthRank[$b['health']], -$b['overdue'], -$b['stale'], Str::lower($b['mission_name'])])
            ->values()
            ->all();
    }

    /**
     * Follow-through per issuing officer: how much of what they issued is
     * still open, overdue, stale, or completed and waiting for them to
     * close it. Busiest backlog first.
     *
     * @param  Collection<int, Directive>  $directives
     * @return array<int, array<string, mixed>>
     */
    private function issuerBoard(Collection $directives): array
    {
        return $directives->groupBy('issued_by_user_id')
            ->map(function (Collection $group): array {
                $counts = $this->countDirectives($group);

                return [
                    'user_id' => $group->first()->issued_by_user_id,
                    'full_name' => $group->first()->issuedBy?->full_name,
                    'total' => $counts['total'],
                    'completion_rate' => $this->percentageOf($counts['completed'], $counts['total']),
                    ...$this->attentionCounts($group),
                ];
            })
            ->sort(fn (array $a, array $b): int => [-($a['awaiting_closure'] + $a['overdue'] + $a['stale']), Str::lower((string) $a['full_name'])]
                <=> [-($b['awaiting_closure'] + $b['overdue'] + $b['stale']), Str::lower((string) $b['full_name'])])
            ->values()
            ->all();
    }

    /**
     * The department's latest directive workflow steps (issued, acknowledged,
     * started, completed, withdrawn, closed), newest first, from the audit
     * trail (FR-AUDIT-001).
     *
     * @param  array{mission_id: string|null, issued_by_user_id: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(string $ministryId, array $filters, int $limit = 12): array
    {
        $logs = $this->statusChangeLogs()
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->whereIn('affected_entity_id', Directive::query()
                ->withoutGlobalScopes()
                ->where('ministry_id', $ministryId)
                ->when($filters['mission_id'] !== null, fn (Builder $query) => $query->where('mission_id', $filters['mission_id']))
                ->when($filters['issued_by_user_id'] !== null, fn (Builder $query) => $query->where('issued_by_user_id', $filters['issued_by_user_id']))
                ->select('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $directives = Directive::query()
            ->withoutGlobalScopes()
            ->with('mission')
            ->whereIn('id', $logs->pluck('affected_entity_id')->unique())
            ->get()
            ->keyBy('id');

        return $logs
            ->filter(fn (AuditLog $log): bool => $directives->has($log->affected_entity_id))
            ->map(function (AuditLog $log) use ($directives): array {
                $directive = $directives->get($log->affected_entity_id);

                return [
                    'id' => $log->id,
                    'directive_id' => $directive->id,
                    'description' => $this->excerpt($directive->description, 140),
                    'mission' => $directive->mission === null ? null : ['id' => $directive->mission->id, 'name' => $directive->mission->name],
                    'from_status' => $log->changes['before']['status'] ?? null,
                    'status' => $log->changes['after']['status'] ?? null,
                    'at' => $log->created_at,
                    'by' => $log->user === null ? null : ['id' => $log->user->id, 'full_name' => $log->user->full_name],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The directive.updated audit rows that record a status change.
     * ModelObserver stores the full record on both sides of every update, so
     * a progress note or a revision (which only bump last_progress_update_at
     * or the target date) also carries a status; only a row whose status
     * differs from the one before it is a workflow step.
     *
     * @return Builder<AuditLog>
     */
    private function statusChangeLogs(): Builder
    {
        return AuditLog::query()
            ->where('affected_entity_type', Directive::class)
            ->where('action', 'directive.updated')
            ->whereNotNull('changes->after->status')
            ->whereRaw("(changes->'after'->>'status') is distinct from (changes->'before'->>'status')");
    }

    /**
     * @param  Collection<int, Directive>  $directives
     * @return array<string, int>
     */
    private function countDirectives(Collection $directives): array
    {
        $byStatus = $directives->countBy(fn (Directive $directive): string => $directive->status->value);
        $byDueState = $directives->countBy(fn (Directive $directive): string => $directive->dueState());
        $status = fn (DirectiveStatus $status): int => (int) $byStatus->get($status->value, 0);

        return [
            'total' => $directives->count(),
            'completed' => $status(DirectiveStatus::Completed) + $status(DirectiveStatus::Closed),
            'closed' => $status(DirectiveStatus::Closed),
            'in_progress' => $status(DirectiveStatus::InProgress),
            'issued' => $status(DirectiveStatus::Issued),
            'acknowledged' => $status(DirectiveStatus::Acknowledged),
            'cancelled' => $status(DirectiveStatus::Cancelled),
            'overdue' => (int) $byDueState->get(Directive::DUE_OVERDUE, 0),
            'approaching' => (int) $byDueState->get(Directive::DUE_APPROACHING, 0),
            'no_target_date' => (int) $byDueState->get(Directive::DUE_NO_DATE, 0),
            'on_track' => (int) $byDueState->get(Directive::DUE_ON_TRACK, 0),
            'stale' => $directives->filter(fn (Directive $directive): bool => $directive->isStale())->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{date_from: string|null, date_to: string|null, mission_id: string|null, issued_by_user_id: string|null}
     */
    private function sanitiseSummaryFilters(array $filters): array
    {
        $date = fn (mixed $value): ?string => self::validDate($value);
        $uuid = fn (mixed $value): ?string => is_string($value) && Str::isUuid($value) ? $value : null;

        return [
            'date_from' => $date($filters['date_from'] ?? null),
            'date_to' => $date($filters['date_to'] ?? null),
            'mission_id' => $uuid($filters['mission_id'] ?? null),
            'issued_by_user_id' => $uuid($filters['issued_by_user_id'] ?? null),
        ];
    }

    /**
     * A strict Y-m-d calendar date, or null.
     */
    public static function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }

    private function percentageOf(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
    }

    private function notifyTargetAttache(Directive $directive): void
    {
        $directive->loadMissing('targetUser');

        $this->notifyUser(
            $directive->targetUser,
            'directive_issued',
            "A new directive has been issued to you: \"{$directive->description}\"",
            $directive,
        );
    }

    /**
     * FR-DIR-007 AC2, FR-DIR-014: the counterpart of the actor hears about
     * every status change (target acts -> issuer; issuer acts -> target).
     * An actor who is neither (e.g. a seeder or console tool) informs both.
     */
    private function notifyStatusChange(Directive $directive, DirectiveStatus $newStatus, User $actor, string $note): void
    {
        $directive->loadMissing(['targetUser', 'issuedBy']);

        $recipients = match ($actor->id) {
            $directive->target_user_id => [$directive->issuedBy],
            $directive->issued_by_user_id => [$directive->targetUser],
            default => [$directive->targetUser, $directive->issuedBy],
        };

        $message = "Directive \"{$this->excerpt($directive->description)}\" was marked {$newStatus->label()} by {$actor->full_name}.";

        if ($note !== '' && in_array($newStatus->value, self::NOTE_REQUIRED_STATUSES, true)) {
            $label = $newStatus === DirectiveStatus::Completed ? 'Summary' : 'Reason';
            $message .= " {$label}: \"{$this->excerpt($note, 140)}\"";
        }

        foreach ($recipients as $recipient) {
            if ($recipient !== null && $recipient->id !== $actor->id) {
                $this->notifyUser($recipient, 'directive_status_changed', $message, $directive);
            }
        }
    }

    /**
     * Notifications quote the directive's text, so they only ever go to a
     * member of the directive's own department (NFR-SEC-006): a target or
     * issuer who has since moved to another ministry (and can no longer
     * open the directive) is skipped rather than sent its content.
     *
     * @return bool Whether a notification was written.
     */
    private function notifyUser(?User $recipient, string $triggerType, string $message, Directive $directive): bool
    {
        if ($recipient === null || $recipient->ministry_id !== $directive->ministry_id) {
            return false;
        }

        $this->notificationService->notify($recipient, $triggerType, $message, "/directives/{$directive->id}");

        return true;
    }

    private function alreadyNotified(User $recipient, string $triggerType, Directive $directive, ?Carbon $since): bool
    {
        return Notification::query()
            ->where('recipient_user_id', $recipient->id)
            ->where('trigger_type', $triggerType)
            ->where('link', "/directives/{$directive->id}")
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
            ->exists();
    }

    private function excerpt(string $text, int $limit = 80): string
    {
        return Str::limit(Str::squish($text), $limit);
    }

    private function formatDate(string $date): string
    {
        return Carbon::parse($date)->format('j M Y');
    }
}
