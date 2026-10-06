<?php

namespace App\Policies;

use App\Models\Mission;
use App\Models\User;
use App\Services\AdministrationService;

/**
 * GET /missions is open to any authenticated user (mission dropdowns are
 * used across every engine); write actions and full-detail fields
 * (MissionResource) are System Administrator only (session 07 task 4).
 *
 * Session 13 adds the FR-HOM-* / FR-MFA-* governance-dashboard abilities
 * here rather than a separate policy class, since a Mission is already the
 * natural authorization subject for "view this mission's activity" — this
 * avoids inventing a non-Eloquent marker resource just to hang a policy on.
 */
class MissionPolicy extends BasePolicy
{
    /**
     * These four abilities are read-only (GET-only controllers, Session 13)
     * but are named for what they show rather than literally "view"/
     * "viewAny", which are the only two names BasePolicy::before() exempts
     * from its blanket denial for the four read-only roles. Since those
     * exact roles are the only ones ever granted these abilities, the
     * exemption list must be extended here or before() would deny its own
     * intended users. {@inheritDoc}
     *
     * @var array<int, string>
     */
    protected const array VIEW_ABILITIES = [
        'view', 'viewAny', 'viewOwnActivity', 'viewMfaAwareness', 'viewMissionDrillDown', 'viewNationalOverview',
    ];

    /**
     * ADR-006: a Ministry Administrator may read the shared mission registry
     * and manage its own department's attache postings, but never create,
     * edit or deactivate a mission (missions are shared by every department).
     */
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['viewAny', 'view', 'managePostings'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Mission $mission): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function update(User $user, Mission $mission): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function deactivate(User $user, Mission $mission): bool
    {
        return $this->isSystemAdministrator($user);
    }

    /**
     * FR-AUTH-021 / BR-004: set or clear which attache a department has
     * posted to a mission. Admin\MissionLinkController additionally pins a
     * Ministry Administrator to its own department's link.
     */
    public function managePostings(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }

    /**
     * FR-HOM-001, FR-HOM-003: Head of Mission and Deputy Head of Mission
     * may view only their own assigned mission's activity, the Deputy at
     * all times, with no activation step. An oversight account without a
     * mission posting is denied (fail closed), as is every other role.
     */
    public function viewOwnActivity(User $user, ?Mission $mission = null): bool
    {
        return in_array($user->role?->name, self::MISSION_OVERSIGHT_ROLES, true)
            && $mission !== null
            && $user->mission_id === $mission->id;
    }

    /**
     * FR-MFA-001: MFA HQ Officer and MFA Principal Secretary see aggregate
     * cross-mission, cross-ministry activity.
     */
    public function viewMfaAwareness(User $user): bool
    {
        return in_array($user->role?->name, self::MFA_ROLES, true);
    }

    /**
     * FR-MFA-002: drill-down into any single mission's summary, available
     * to the same roles as the aggregate awareness view.
     */
    public function viewMissionDrillDown(User $user, Mission $mission): bool
    {
        return $this->viewMfaAwareness($user);
    }

    /**
     * FR-MFA-003: the national, cross-mission/cross-ministry comparison is
     * MFA Principal Secretary only.
     */
    public function viewNationalOverview(User $user): bool
    {
        return $user->role?->name === 'MFA Principal Secretary';
    }

    private function isSystemAdministrator(User $user): bool
    {
        return $user->role?->name === 'System Administrator';
    }
}
