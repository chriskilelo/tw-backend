<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ShowAdministrationDashboardRequest;
use App\Models\User;
use App\Services\AdministrationDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The administrator dashboard (ADR-006): System Administrator across every
 * department, Ministry Administrator for its own only (UserPolicy::
 * viewDashboard()). Not ministry.scope-wrapped, like the other account
 * routes, because the service filters by department explicitly.
 */
class AdministrationDashboardController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AdministrationDashboardService $administrationDashboardService) {}

    public function show(ShowAdministrationDashboardRequest $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return $this->respondWithData(
            $this->administrationDashboardService->build($request->user(), $request->validated('ministry')),
        );
    }
}
