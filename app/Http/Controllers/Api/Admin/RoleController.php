<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * ADR-006 / FR-AUTH-021: the role picker for account administration. A
 * System Administrator sees the whole catalogue; a Ministry Administrator
 * sees only the roles it may assign (the PS goes through an approval
 * request instead, BR-027). Gated like account listing itself.
 */
class RoleController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $roles = Role::query()
            ->when(
                AdministrationService::isMinistryAdministrator($request->user()),
                fn ($query) => $query->whereIn('name', AdministrationService::MINISTRY_ADMIN_ASSIGNABLE_ROLES),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'layer', 'scope', 'display_title']);

        return $this->respondWithData($roles->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'layer' => $role->layer,
            'scope' => $role->scope,
            'display_title' => $role->display_title,
        ]));
    }
}
