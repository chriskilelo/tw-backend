<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ShowDashboardRequest;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * The role-shaped home dashboard (FR-KPI-010 for the attache view). No
 * dedicated policy, same reasoning as SearchController: every figure is
 * built from the requesting user's own ministry (ministry.scope plus the
 * models' global scope), and the attache view is further pinned to the
 * user's own mission, so there is no cross-user record for a policy to
 * guard. HRM&D Officers and Ministry Administrators never reach this
 * controller: the ministry.scope middleware 403s both here (FR-SDT-018,
 * BR-025).
 */
class DashboardController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly DashboardService $dashboardService) {}

    public function show(ShowDashboardRequest $request): JsonResponse
    {
        return $this->respondWithData(
            $this->dashboardService->forUser($request->user(), $request->validated('kpi_cycle')),
        );
    }
}
