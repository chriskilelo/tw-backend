<?php

namespace App\Policies;

use App\Models\Directive;
use App\Models\User;

/**
 * FR-DIR-* role requirements (URD Section 10.5, API-001 Section 8).
 * BasePolicy::before() already denies every non-view ability for the four
 * BR-020 read-only roles, and every ability for the HRM&D Officer and the
 * Ministry Administrator, before any method here runs.
 *
 * Only the roles in DIRECTIVE_ROLES may use the module at all. The four
 * read-only mission-governance roles bypass ministry scope, so letting
 * them reach the list would expose every department's directives
 * (viewAny is therefore a role check, not "true").
 *
 * view() narrows per role: a Ministry Attache sees the directives that
 * target them, a Ministry HQ Officer the ones they issued, and the PS,
 * Acting PS, HQ Director and System Administrator see the whole ministry
 * (read-only unless they issued the directive). DirectiveController::index()
 * applies the equivalent query-level narrowing to the list.
 */
class DirectivePolicy extends BasePolicy
{
    /**
     * @var array<int, string>
     */
    public const array DIRECTIVE_ROLES = [
        'Ministry Attache',
        'Ministry HQ Officer',
        'Ministry PS',
        'Acting PS',
        'Ministry HQ Director',
        'System Administrator',
    ];

    /**
     * FR-DIR-002: roles that issue directives. Acting PS carries the full PS
     * permission set (FR-SDT-004 AC1 role-swap precedent).
     *
     * @var array<int, string>
     */
    public const array ISSUING_ROLES = ['Ministry HQ Officer', 'Ministry PS', 'Acting PS'];

    /**
     * FR-DIR-012 summary dashboard roles.
     *
     * @var array<int, string>
     */
    public const array SUMMARY_ROLES = ['Ministry HQ Director', 'Ministry PS', 'Acting PS'];

    /**
     * Statuses the target attache moves a directive into (FR-DIR-006, 007).
     *
     * @var array<int, string>
     */
    public const array TARGET_DRIVEN_STATUSES = ['acknowledged', 'in_progress', 'completed'];

    /**
     * Statuses the issuer moves a directive into: withdraw (cancelled) and
     * accept-and-close (closed), per the URD lifecycle diagram.
     *
     * @var array<int, string>
     */
    public const array ISSUER_DRIVEN_STATUSES = ['cancelled', 'closed'];

    /**
     * @var array<int, string>
     */
    private const array MINISTRY_WIDE_ROLES = ['Ministry PS', 'Acting PS', 'Ministry HQ Director', 'System Administrator'];

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, self::DIRECTIVE_ROLES);
    }

    public function view(User $user, Directive $directive): bool
    {
        return match ($user->role?->name) {
            'Ministry Attache' => $user->id === $directive->target_user_id,
            'Ministry HQ Officer' => $user->id === $directive->issued_by_user_id,
            default => $this->hasRole($user, self::MINISTRY_WIDE_ROLES),
        };
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, self::ISSUING_ROLES);
    }

    /**
     * GET /directives/assignees feeds the issue form, so it follows create().
     */
    public function listAssignees(User $user): bool
    {
        return $this->create($user);
    }

    /**
     * Called as Gate::authorize('transitionStatus', [$directive, $status]).
     * Whether the move is legal from the current status is the state
     * machine's job (DirectiveService::canTransition()).
     */
    public function transitionStatus(User $user, Directive $directive, string $newStatus): bool
    {
        if (in_array($newStatus, self::TARGET_DRIVEN_STATUSES, true)) {
            return $user->id === $directive->target_user_id;
        }

        if (in_array($newStatus, self::ISSUER_DRIVEN_STATUSES, true)) {
            return $user->id === $directive->issued_by_user_id;
        }

        return false;
    }

    /**
     * FR-DIR-003 "optional and revisable": only the issuer. Like
     * transitionStatus(), this answers "who"; "only while open" is the
     * state machine's rule (DirectiveService::reviseDirective() answers 422
     * for a finished directive, and allowedActions() combines both).
     */
    public function revise(User $user, Directive $directive): bool
    {
        return $user->id === $directive->issued_by_user_id;
    }

    /**
     * FR-DIR-008 (target attache progress notes) and FR-DIR-011 (issuer
     * follow-up notes), in any status.
     */
    public function addNote(User $user, Directive $directive): bool
    {
        return $user->id === $directive->target_user_id || $user->id === $directive->issued_by_user_id;
    }

    /**
     * FR-DIR-012: the director-level summary dashboard. A class-level
     * ability, mirroring ReportPolicy::viewCompliance().
     */
    public function viewSummary(User $user): bool
    {
        return $this->hasRole($user, self::SUMMARY_ROLES);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function hasRole(User $user, array $roles): bool
    {
        return in_array($user->role?->name, $roles, true);
    }
}
