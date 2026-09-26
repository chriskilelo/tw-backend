<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AdministrationService;

/**
 * FR-AUDIT-005: the full audit trail is System Administrator only.
 * FR-AUDIT-006: a Ministry Administrator sees its own department's
 * administrative entries; Admin\AuditLogController applies that filter.
 */
class AuditLogPolicy extends BasePolicy
{
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['viewAny'];

    public function viewAny(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }
}
