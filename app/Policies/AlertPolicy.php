<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

/**
 * FR-ALERT-* role requirements (CLAUDE.md Section 10, API-001 Section 6).
 * BasePolicy::before() already denies every non-view ability for the four
 * BR-020 read-only roles before any method here runs.
 *
 * Mirrors the role grants already encoded in
 * App\Services\PermissionCatalogueService::catalogue(): alert.create/edit
 * -> Ministry Attache; alert.delegate -> Ministry PS, Acting PS;
 * alert.acknowledge/feedback.post -> Ministry HQ Officer, Ministry PS,
 * Designated Deputy, Acting PS.
 */
class AlertPolicy extends BasePolicy
{
    private const array DELEGATING_ROLES = ['Ministry PS', 'Acting PS'];

    private const array ACKNOWLEDGING_ROLES = ['Ministry HQ Officer', 'Ministry PS', 'Designated Deputy', 'Acting PS'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Alert $alert): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role?->name === 'Ministry Attache';
    }

    public function update(User $user, Alert $alert): bool
    {
        return $user->role?->name === 'Ministry Attache' && $alert->submitted_by_user_id === $user->id;
    }

    public function uploadAttachment(User $user, Alert $alert): bool
    {
        return $this->update($user, $alert);
    }

    public function delegate(User $user, Alert $alert): bool
    {
        return in_array($user->role?->name, self::DELEGATING_ROLES, true);
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        return in_array($user->role?->name, self::ACKNOWLEDGING_ROLES, true);
    }

    public function postFeedback(User $user, Alert $alert): bool
    {
        return in_array($user->role?->name, self::ACKNOWLEDGING_ROLES, true)
            || $user->role?->name === 'Ministry Attache';
    }
}
