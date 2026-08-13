<?php

namespace App\Policies;

use App\Models\User;

/**
 * Admin\UserController is System Administrator only (session 07 task 1).
 * BasePolicy::before() already denies every non-view ability for the four
 * BR-020 read-only roles; every ability here is additionally restricted to
 * System Administrator alone, since even write-capable Layer 2/3 roles
 * have no business managing accounts.
 */
class UserPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function deactivate(User $user, User $model): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function reactivate(User $user, User $model): bool
    {
        return $this->isSystemAdministrator($user);
    }

    public function resendActivation(User $user, User $model): bool
    {
        return $this->isSystemAdministrator($user);
    }

    private function isSystemAdministrator(User $user): bool
    {
        return $user->role?->name === 'System Administrator';
    }
}
