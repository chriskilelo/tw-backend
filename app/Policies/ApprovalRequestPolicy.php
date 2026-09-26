<?php

namespace App\Policies;

use App\Enums\ApprovalRequestStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\AdministrationService;

/**
 * FR-AUTH-022/023, BR-027: a Ministry Administrator raises and tracks its own
 * department's Principal Secretary requests; only a System Administrator
 * decides them. Cross-department requests never reach view(): the model's
 * ministry global scope makes route binding 404 first.
 */
class ApprovalRequestPolicy extends BasePolicy
{
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['viewAny', 'view', 'create', 'cancel'];

    public function viewAny(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }

    public function view(User $user, ApprovalRequest $approvalRequest): bool
    {
        return AdministrationService::canAdministerMinistry($user, $approvalRequest->ministry_id);
    }

    public function create(User $user): bool
    {
        return AdministrationService::isMinistryAdministrator($user);
    }

    public function decide(User $user, ApprovalRequest $approvalRequest): bool
    {
        return AdministrationService::isSystemAdministrator($user);
    }

    public function cancel(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $approvalRequest->requested_by_user_id === $user->id
            && $approvalRequest->status === ApprovalRequestStatus::Pending;
    }
}
