<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Policies\BasePolicy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The list-side twin of BasePolicy::governanceReadAccess(), for records
 * that carry a direct mission_id (alerts, inquiries). The four BR-020 roles
 * bypass ministry scoping (App\Http\Middleware\MinistryScope), so without
 * this an engine list, search or country profile would hand them every
 * department's records at every mission:
 *
 *  - Head / Deputy Head of Mission: their own mission's records, from every
 *    department (FR-HOM-001, FR-HOM-003). No mission posting, nothing.
 *  - MFA HQ Officer / MFA Principal Secretary: nothing. They see aggregate
 *    counts and metadata through the MFA awareness view only (FR-MFA-001
 *    AC2).
 *  - Every other role: unchanged. Ministry isolation stays with the global
 *    scope (CLAUDE.md Section 4, Rule 1).
 *
 * PeriodicReport has its own visibleTo() (drafts are never shown outside
 * the mission), which applies the same governance rules.
 */
trait HasGovernanceVisibility
{
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $role = $user->role?->name;

        if (in_array($role, BasePolicy::MFA_ROLES, true)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (in_array($role, BasePolicy::MISSION_OVERSIGHT_ROLES, true)) {
            if ($user->mission_id === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($query->qualifyColumn('mission_id'), $user->mission_id);
        }
    }
}
