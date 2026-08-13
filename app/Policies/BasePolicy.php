<?php

namespace App\Policies;

use App\Models\User;

/**
 * Base class for every engine policy (CLAUDE.md Section 4, Rule 2 / BR-020,
 * FR-AUTH-017, DES-003). The four mission-governance roles are structurally
 * read-only: the before() hook denies any non-view ability for them before
 * a concrete policy method ever runs, so the restriction cannot be bypassed
 * by a policy that forgets to check the role itself.
 */
abstract class BasePolicy
{
    /**
     * Roles restricted to read-only access at the permission-catalogue level.
     *
     * @var array<int, string>
     */
    public const array READ_ONLY_ROLES = [
        'Head of Mission',
        'Deputy Head of Mission',
        'MFA HQ Officer',
        'MFA Principal Secretary',
    ];

    /**
     * Abilities that represent read access and are exempt from the blanket
     * denial below.
     *
     * @var array<int, string>
     */
    protected const array VIEW_ABILITIES = ['view', 'viewAny'];

    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, static::VIEW_ABILITIES, true)) {
            return null;
        }

        if (in_array($user->role?->name, static::READ_ONLY_ROLES, true)) {
            return false;
        }

        return null;
    }
}
