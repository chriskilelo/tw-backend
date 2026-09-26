<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AdministrationService;

/**
 * Session 14: FR-SDT-001 (PS dashboard), FR-SDT-004 to 006 (Acting PS),
 * FR-SDT-012/013 (HQ Officer workspace). Auto-discovered by Laravel's
 * convention (App\Models\Ministry -> App\Policies\MinistryPolicy), unlike
 * ReferralPolicy which needed manual registration.
 *
 * None of these abilities are ever intended for the four BR-020 read-only
 * roles, so BasePolicy::before()'s blanket denial for non-view abilities
 * never needs the VIEW_ABILITIES override MissionPolicy required.
 */
class MinistryPolicy extends BasePolicy
{
    /**
     * ADR-006: the department's own record and its leadership switches are
     * the only Ministry abilities a Ministry Administrator may use; the PS
     * dashboard and HQ workspace are operational (BR-025).
     */
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['viewAny', 'manageActingPs', 'manageDesignatedDeputy'];

    /**
     * ADR-006: GET /ministries. Admin\MinistryController narrows a Ministry
     * Administrator to its own department.
     */
    public function viewAny(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }

    /**
     * ADR-006: onboarding a new department is System Administrator only.
     */
    public function create(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user);
    }

    /**
     * FR-SDT-001: the Ministry PS dashboard. Also granted to the Acting PS
     * role (FR-SDT-004 AC1: activation grants "the full permission set of
     * the Ministry PS role").
     */
    public function viewPsDashboard(User $user): bool
    {
        return in_array($user->role?->name, ['Ministry PS', 'Acting PS'], true);
    }

    /**
     * FR-SDT-012, FR-SDT-013: the HQ Trade Officer's unified workspace.
     */
    public function viewHqWorkspace(User $user): bool
    {
        return $user->role?->name === 'Ministry HQ Officer';
    }

    /**
     * FR-SDT-004 AC1: activated by "the SDT PS or a System Administrator";
     * ADR-006 adds the department's Ministry Administrator. FR-SDT-006 names
     * no separate deactivating actor, so the same roles act in both
     * directions. SdtService pins every actor but a System Administrator to
     * its own department.
     */
    public function manageActingPs(User $user): bool
    {
        return in_array($user->role?->name, ['Ministry PS', 'System Administrator', 'Ministry Administrator'], true);
    }

    /**
     * FR-SDT-003, FR-ALERT-008: switch the Designated Deputy fallback on or
     * off. Acting PS is included because activation carries the full PS
     * permission set (FR-SDT-004 AC1).
     */
    public function manageDesignatedDeputy(User $user): bool
    {
        return in_array($user->role?->name, ['Ministry PS', 'Acting PS', 'System Administrator', 'Ministry Administrator'], true);
    }
}
