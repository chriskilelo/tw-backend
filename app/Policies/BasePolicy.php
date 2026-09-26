<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AdministrationService;

/**
 * Base class for every engine policy (CLAUDE.md Section 4, Rule 2 / BR-020,
 * FR-AUTH-017, DES-003). The four mission-governance roles are structurally
 * read-only: the before() hook denies any non-view ability for them before
 * a concrete policy method ever runs, so the restriction cannot be bypassed
 * by a policy that forgets to check the role itself.
 *
 * FR-SDT-018: the HRM&D Officer role is subject to a second, stricter
 * before() check — unlike the four read-only roles above (still allowed
 * every view/viewAny ability, on every policy), HRM&D's restriction is
 * engine-scoped, not action-scoped: FR-SDT-016 gives them "no access to
 * any other Layer 2 module," including read access. So this check runs
 * first and denies ALL abilities, view included, on every policy except
 * the one that overrides isKpiScoped() to return true (KpiPolicy).
 *
 * ADR-006 / BR-025: the Ministry Administrator is denied every ability —
 * view included — unless the concrete policy lists it in
 * MINISTRY_ADMINISTRATION_ABILITIES. The allowlist is per ability, not per
 * policy, because several policies (ReferralPolicy, ReportPolicy, KpiPolicy)
 * govern both operational records and department configuration.
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

    /**
     * Administrative abilities a Ministry Administrator may use on this
     * policy; empty by default, so every operational policy denies it.
     *
     * @var array<int, string>
     */
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = [];

    public function before(User $user, string $ability): ?bool
    {
        if (AdministrationService::isMinistryAdministrator($user) && ! in_array($ability, static::MINISTRY_ADMINISTRATION_ABILITIES, true)) {
            return false;
        }

        if ($user->role?->name === 'HRM&D Officer' && ! $this->isKpiScoped()) {
            return false;
        }

        if (in_array($ability, static::VIEW_ABILITIES, true)) {
            return null;
        }

        if (in_array($user->role?->name, static::READ_ONLY_ROLES, true)) {
            return false;
        }

        return null;
    }

    /**
     * Overridden by KpiPolicy — the only policy an HRM&D Officer may ever
     * use (FR-SDT-018).
     */
    protected function isKpiScoped(): bool
    {
        return false;
    }
}
