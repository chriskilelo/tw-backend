<?php

namespace App\Policies;

use App\Models\MasterDataEntry;
use App\Models\User;
use App\Services\AdministrationService;

/**
 * FR-MDATA-001, FR-MDATA-002 (API-001 Section 3): GET is open to any
 * authenticated ministry-scoped user (every engine's dropdowns depend on
 * it); add/edit/deactivate is System Administrator only.
 */
class MasterDataEntryPolicy extends BasePolicy
{
    /**
     * ADR-006: a Ministry Administrator manages its own department's lists;
     * the controllers pin every query and write to that department.
     */
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['viewAny', 'create', 'update', 'manage'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, MasterDataEntry $masterDataEntry): bool
    {
        return AdministrationService::canAdministerMinistry($user, $masterDataEntry->ministry_id);
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
        return $this->isAdministrator($user);
    }

    private function isAdministrator(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }
}
