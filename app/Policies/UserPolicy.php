<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AdministrationService;

/**
 * Account management (session 07 task 1; ADR-006 / FR-AUTH-021).
 *
 * A System Administrator manages every account. A Ministry Administrator
 * manages accounts in its own department only, and never an administrator
 * account (its own included) — those are reserved to the System
 * Administrator (BR-028). Admin\UserController returns 404 before reaching
 * this policy when the target is outside the actor's department, so the
 * existence of other departments' accounts is never confirmed.
 *
 * Which roles a Ministry Administrator may assign, and the PS approval
 * requirement (BR-027), are enforced by the form requests and
 * AdministrationService, not here.
 */
class UserPolicy extends BasePolicy
{
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = [
        'viewAny', 'view', 'create', 'update', 'deactivate', 'reactivate', 'resendActivation', 'viewDashboard',
    ];

    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    /**
     * The administrator dashboard (AdministrationDashboardService): account
     * health and seat rules for the department(s) the actor administers.
     */
    public function viewDashboard(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, User $model): bool
    {
        return AdministrationService::canAdministerMinistry($user, $model->ministry_id);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->canManageAccount($user, $model);
    }

    public function deactivate(User $user, User $model): bool
    {
        return $this->canManageAccount($user, $model);
    }

    public function reactivate(User $user, User $model): bool
    {
        return $this->canManageAccount($user, $model);
    }

    public function resendActivation(User $user, User $model): bool
    {
        return $this->canManageAccount($user, $model);
    }

    private function isAdministrator(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }

    private function canManageAccount(User $user, User $model): bool
    {
        if (AdministrationService::isSystemAdministrator($user)) {
            return true;
        }

        return AdministrationService::canAdministerMinistry($user, $model->ministry_id)
            && ! AdministrationService::isSystemAdministrator($model)
            && ! AdministrationService::isMinistryAdministrator($model);
    }
}
