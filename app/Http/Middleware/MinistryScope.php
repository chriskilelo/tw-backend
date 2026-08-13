<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Policies\BasePolicy;
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
 */
class MinistryScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        app()->instance('current_ministry_id', $this->resolveMinistryId($user));

        return $next($request);
    }

    protected function resolveMinistryId(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if (in_array($user->role?->name, $this->bypassRoleNames(), true)) {
            return null;
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
