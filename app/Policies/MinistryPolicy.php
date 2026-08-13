<?php

namespace App\Policies;

use App\Models\User;

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
     * FR-SDT-004 AC1: activated by "the SDT PS or a System Administrator".
     * FR-SDT-006 does not name a separate deactivating actor, so the same
     * two roles are used for both directions.
     */
    public function manageActingPs(User $user): bool
    {
        return in_array($user->role?->name, ['Ministry PS', 'System Administrator'], true);
    }
}
