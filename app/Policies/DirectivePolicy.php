<?php

namespace App\Policies;

use App\Models\Directive;
use App\Models\User;

/**
 * FR-DIR-* role requirements (API-001 Section 8). BasePolicy::before()
 * already denies every non-view ability for the four BR-020 read-only
 * roles before any method here runs.
 *
 * view() narrows per API-001's literal scoping ("Ministry Attache: own
 * mission, as target; Ministry HQ Officer: own issued; Ministry PS,
 * Ministry HQ Director: all, read-only for Directors") — unlike
 * AlertPolicy/InquiryPolicy, which leave view open to any ministry-scoped
 * user, because a directive additionally carries a specific target
 * attache and issuer that the list/detail scoping must respect.
 * DirectiveController::index() applies the equivalent query-level
 * narrowing for the list endpoint.
 */
class DirectivePolicy extends BasePolicy
{
    private const array MINISTRY_WIDE_ROLES = ['Ministry PS', 'Ministry HQ Director', 'Acting PS', 'System Administrator'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Directive $directive): bool
    {
        return match ($user->role?->name) {
            'Ministry Attache' => $user->id === $directive->target_user_id,
            'Ministry HQ Officer' => $user->id === $directive->issued_by_user_id,
            default => in_array($user->role?->name, self::MINISTRY_WIDE_ROLES, true),
        };
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['Ministry HQ Officer', 'Ministry PS'], true);
    }

    /**
     * API-001 Section 8: status transitions are performed by the target
     * attache only.
     */
    public function transitionStatus(User $user, Directive $directive): bool
    {
        return $user->id === $directive->target_user_id;
    }

    /**
     * API-001 Section 8: progress/follow-up notes may be added by the
     * target attache or the issuing HQ officer.
     */
    public function addNote(User $user, Directive $directive): bool
    {
        return $user->id === $directive->target_user_id || $user->id === $directive->issued_by_user_id;
    }

    /**
     * FR-DIR-012: the director-level summary dashboard. No model instance
     * to check — a class-level ability, mirroring
     * ReportPolicy::viewCompliance()'s precedent.
     */
    public function viewSummary(User $user): bool
    {
        return in_array($user->role?->name, ['Ministry HQ Director', 'Ministry PS'], true);
    }
}
