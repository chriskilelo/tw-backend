<?php

namespace App\Services;

use App\Enums\DirectiveStatus;
use App\Models\Directive;
use App\Models\DirectiveNote;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-DIR-002, 006 to 010, 012, 013: business logic
 * for the Directive and Tasking Engine. Directives\DirectiveController
 * stays thin and delegates every mutation here.
 *
 * BR-018: a directive's target mission and target attache are immutable
 * once issued. The schema has no dedicated issued_at column (CLAUDE.md
 * Section 6); since issueDirective() creates the draft row and issues it
 * within the same transaction, created_at already marks the issuance
 * moment, and last_progress_update_at is initialised at issue time so the
 * FR-DIR-010 staleness clock starts from issuance, not from the first
 * subsequent status transition.
 */
class DirectiveService
{
    /**
     * CLAUDE.md Section 8 Stale Directive Threshold (FR-DIR-010).
     */
    public const int STALE_THRESHOLD_DAYS = 14;

    /**
     * Keyed by target status, valued by the statuses that target may be
     * reached from (issued -> acknowledged -> in_progress -> completed;
     * issued/in_progress -> cancelled).
     *
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        'acknowledged' => ['issued'],
        'in_progress' => ['acknowledged'],
        'completed' => ['in_progress'],
        'cancelled' => ['issued', 'in_progress'],
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

    /**
     * @throws InvalidArgumentException If $newStatus is not a configured
     *                                  transition target, the current
     *                                  status may not transition to it, or
     *                                  (FR-DIR-007) $newStatus is
     *                                  'completed' with no non-empty $note
     *                                  to store as completion_summary.
     */
    public function transitionStatus(Directive $directive, string $newStatus, User $actor, ?string $note): void
    {
        $allowedSources = self::ALLOWED_TRANSITIONS[$newStatus] ?? null;

        if ($allowedSources === null) {
            throw new InvalidArgumentException("[{$newStatus}] is not a configured directive status transition.");
        }

        if (! in_array($directive->status->value, $allowedSources, true)) {
            throw new InvalidArgumentException(
                "Cannot transition a directive from [{$directive->status->value}] to [{$newStatus}]."
            );
        }

        $isCompleting = $newStatus === DirectiveStatus::Completed->value;

        if ($isCompleting && trim((string) $note) === '') {
            throw new InvalidArgumentException('A completion summary is required to complete a directive (FR-DIR-007).');
        }

        DB::transaction(function () use ($directive, $newStatus, $actor, $note, $isCompleting): void {
            $directive->forceFill([
                'status' => $newStatus,
                'last_progress_update_at' => now(),
                ...($isCompleting ? ['completion_summary' => $note] : []),
            ])->save();

            DirectiveNote::create([
                'directive_id' => $directive->id,
                'authored_by_user_id' => $actor->id,
                'content' => (trim((string) $note) !== '') ? $note : "Status changed to {$newStatus}.",
            ]);
        });
    }

    public function addNote(Directive $directive, string $content, User $actor): DirectiveNote
    {
        return DirectiveNote::create([
            'directive_id' => $directive->id,
            'authored_by_user_id' => $actor->id,
            'content' => $content,
        ]);
    }

    /**
     * FR-DIR-010: directives in_progress with no progress update for at
     * least the configured stale threshold. Runs unscoped (a scheduled
     * command has no authenticated user to bind ministry.scope from).
     */
    public function flagStaleDirectives(): void
    {
        $staleBefore = now()->subDays(self::STALE_THRESHOLD_DAYS);

        Directive::query()
            ->withoutGlobalScopes()
            ->where('status', DirectiveStatus::InProgress)
            ->where('last_progress_update_at', '<', $staleBefore)
            ->with('targetUser')
            ->each(function (Directive $directive): void {
                if ($directive->targetUser === null) {
                    return;
                }

                $this->notificationService->notify(
                    $directive->targetUser,
                    'directive_stale',
                    "Directive \"{$directive->description}\" has had no progress update in over ".self::STALE_THRESHOLD_DAYS.' days.',
                    "/directives/{$directive->id}",
                );
            });
    }

    /**
     * FR-DIR-012: completed/in-progress/overdue counts and percentages for
     * a ministry's directives.
     *
     * @return array<string, mixed>
     */
    public function getSummary(string $ministryId): array
    {
        $directives = Directive::query()->withoutGlobalScopes()->where('ministry_id', $ministryId)->get();

        $total = $directives->count();
        $completed = $directives->where('status', DirectiveStatus::Completed)->count();
        $inProgress = $directives->where('status', DirectiveStatus::InProgress)->count();
        $overdue = $directives->filter(fn (Directive $directive) => $this->isOverdue($directive))->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'issued' => $directives->where('status', DirectiveStatus::Issued)->count(),
            'acknowledged' => $directives->where('status', DirectiveStatus::Acknowledged)->count(),
            'cancelled' => $directives->where('status', DirectiveStatus::Cancelled)->count(),
            'overdue' => $overdue,
            'percentages' => [
                'completed' => $this->percentageOf($completed, $total),
                'in_progress' => $this->percentageOf($inProgress, $total),
                'overdue' => $this->percentageOf($overdue, $total),
            ],
        ];
    }

    /**
     * FR-SDT-008: PS-level directive overview — the ministry summary plus
     * the most recently issued directives and the currently stale ones.
     *
     * @return array<string, mixed>
     */
    public function getOverview(string $ministryId): array
    {
        $directives = Directive::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->with(['mission', 'targetUser'])
            ->orderByDesc('created_at')
            ->get();

        $stale = $directives
            ->where('status', DirectiveStatus::InProgress)
            ->filter(fn (Directive $directive) => $directive->last_progress_update_at !== null
                && $directive->last_progress_update_at->lt(now()->subDays(self::STALE_THRESHOLD_DAYS)));

        return [
            'summary' => $this->getSummary($ministryId),
            'recent_directives' => $directives->take(10)->map($this->presentDirective(...))->values(),
            'stale_directives' => $stale->map($this->presentDirective(...))->values(),
        ];
    }

    /**
     * FR-SDT-009: per-mission completion rate dashboard, mirroring
     * ReportService::getComplianceDashboard()'s established pattern of
     * resolving a ministry's missions via mission_ministry_links.
     *
     * @return array<string, mixed>
     */
    public function getComplianceDashboard(string $ministryId): array
    {
        $missions = MissionMinistryLink::query()
            ->where('ministry_id', $ministryId)
            ->with('mission')
            ->get()
            ->pluck('mission')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $directivesByMission = Directive::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->get()
            ->groupBy('mission_id');

        $missionRows = $missions->map(function (Mission $mission) use ($directivesByMission): array {
            $directives = $directivesByMission->get($mission->id, collect());
            $total = $directives->count();
            $completed = $directives->where('status', DirectiveStatus::Completed)->count();
            $overdue = $directives->filter(fn (Directive $directive) => $this->isOverdue($directive))->count();

            return [
                'mission_id' => $mission->id,
                'mission_name' => $mission->name,
                'total' => $total,
                'completed' => $completed,
                'overdue' => $overdue,
                'completion_rate' => $this->percentageOf($completed, $total),
            ];
        })->values();

        return [
            'missions' => $missionRows,
            'summary' => $this->getSummary($ministryId),
        ];
    }

    private function isOverdue(Directive $directive): bool
    {
        return $directive->target_completion_date !== null
            && $directive->target_completion_date->isPast()
            && ! in_array($directive->status, [DirectiveStatus::Completed, DirectiveStatus::Cancelled, DirectiveStatus::Closed], true);
    }

    private function percentageOf(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDirective(Directive $directive): array
    {
        return [
            'id' => $directive->id,
            'description' => $directive->description,
            'status' => $directive->status,
            'target_completion_date' => $directive->target_completion_date,
            'last_progress_update_at' => $directive->last_progress_update_at,
            'mission' => $directive->mission === null ? null : [
                'id' => $directive->mission->id,
                'name' => $directive->mission->name,
            ],
            'target_user' => $directive->targetUser === null ? null : [
                'id' => $directive->targetUser->id,
                'full_name' => $directive->targetUser->full_name,
            ],
        ];
    }

    private function notifyTargetAttache(Directive $directive): void
    {
        $directive->loadMissing('targetUser');

        if ($directive->targetUser === null) {
            return;
        }

        $this->notificationService->notify(
            $directive->targetUser,
            'directive_issued',
            "A new directive has been issued to you: \"{$directive->description}\"",
            "/directives/{$directive->id}",
        );
    }
}
