<?php

namespace App\Http\Middleware;

use App\Models\Scopes\MinistryScope as ModelMinistryScope;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Services\AdministrationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated user's ministry_id and binds it into the
 * container as 'current_ministry_id' (CLAUDE.md Section 4, Rule 1;
 * NFR-SEC-006). App\Models\Scopes\MinistryScope, applied to every Layer 2
 * model via the HasMinistryScope trait (Session 4), reads this binding to
 * filter every query without depending on the Auth facade directly.
 *
 * Platform-scoped roles that are not tied to a single ministry bypass
 * scoping entirely: System Administrator (sees everything) and the four
 * structurally read-only mission-governance roles (oversee an entire
 * mission or the whole platform, not one ministry).
 *
 * FR-SDT-018: also enforces the HRM&D Officer's engine-scoped restriction
 * as a blanket, defence-in-depth check for every ministry-scoped route —
 * BasePolicy::before() (Session 33) already denies HRM&D Officer on any
 * policy-gated action, but a couple of routes this middleware also guards
 * (e.g. /search) have no dedicated policy at all, so this second check
 * covers those too, not just the policy-gated ones.
 *
 * BR-025 / ADR-006: the same blanket net for the Ministry Administrator,
 * whose only ministry-scoped routes are the three department-configuration
 * groups below. It is deliberately NOT a bypass role: its ministry_id is
 * bound like any other department user's, so those configuration routes are
 * confined to its own department by the model global scope.
 */
class MinistryScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user !== null && $user->role?->name === 'HRM&D Officer' && ! $this->isKpiScopedPath($request)) {
            abort(403, 'HRM&D Officer access is limited to the KPI Framework Engine (FR-SDT-018).');
        }

        if (AdministrationService::isMinistryAdministrator($user) && ! $this->isMinistryAdministrationPath($request)) {
            abort(403, 'Ministry Administrator access is limited to department administration (BR-025).');
        }

        app()->instance('current_ministry_id', $this->resolveMinistryId($user));

        return $next($request);
    }

    private function isKpiScopedPath(Request $request): bool
    {
        return $request->is('api/v1/kpi-*') || $request->is('api/v1/sdt/hrmd-dashboard*');
    }

    private function isMinistryAdministrationPath(Request $request): bool
    {
        return $request->is('api/v1/report-templates*')
            || $request->is('api/v1/kpi-definitions*')
            || $request->is('api/v1/kpi-profiles*');
    }

    /**
     * A bypass role resolves to MinistryScope::UNSCOPED whatever its
     * ministry_id holds, so the bypass is decided by role name alone, as
     * documented above. The governance roles are then confined per record
     * by their policies and the models' visibleTo() scopes instead.
     */
    protected function resolveMinistryId(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if (in_array($user->role?->name, $this->bypassRoleNames(), true)) {
            return ModelMinistryScope::UNSCOPED;
        }

        return $user->ministry_id;
    }

    /**
     * @return array<int, string>
     */
    protected function bypassRoleNames(): array
    {
        return [...BasePolicy::READ_ONLY_ROLES, 'System Administrator'];
    }
}
