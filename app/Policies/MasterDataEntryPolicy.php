<?php

namespace App\Policies;

use App\Models\MasterDataEntry;
use App\Models\User;

/**
 * FR-MDATA-001, FR-MDATA-002 (API-001 Section 3): GET is open to any
 * authenticated ministry-scoped user (every engine's dropdowns depend on
 * it); add/edit/deactivate is System Administrator only.
 */
class MasterDataEntryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function update(User $user, MasterDataEntry $masterDataEntry): bool
    {
        return $this->isSystemAdministrator($user);
    }

    /**
     * Session 14 / FR-SDT-019, FR-SDT-020: the SDT-scoped configuration
     * screens (App\Http\Controllers\Api\Sdt\ConfigController) are System
     * Administrator only end-to-end, including GET — unlike viewAny()
     * above, which intentionally stays open to any authenticated user for
     * the platform-wide /master-data dropdown endpoint.
     */
    public function manage(User $user): bool
    {
        return $this->isSystemAdministrator($user);
    }

    private function isSystemAdministrator(User $user): bool
    {
        return $user->role?->name === 'System Administrator';
    }
}
