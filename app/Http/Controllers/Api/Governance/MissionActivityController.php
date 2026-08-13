<?php

namespace App\Http\Controllers\Api\Governance;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Mission;
use App\Services\GovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-HOM-001 to 003: read-only mission activity feed and summary metrics
 * for the Head of Mission and Deputy Head of Mission, scoped to their own
 * assigned mission (MissionPolicy::viewOwnActivity()). BasePolicy::before()
 * (CLAUDE.md Section 4, Rule 2) structurally denies any non-GET ability for
 * this role before either method here could ever be reached.
 */
class MissionActivityController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly GovernanceService $governanceService) {}

    public function index(Request $request): JsonResponse
    {
        $mission = $this->authorizedOwnMission($request);

        $ministryId = $request->filled('ministry_id') ? $request->string('ministry_id')->value() : null;
        $items = $this->governanceService->missionActivityFeed($mission, $ministryId);

        $perPage = min((int) $request->integer('per_page', 25), 100);
        $page = max((int) $request->integer('page', 1), 1);

        return $this->respondWithData($items->forPage($page, $perPage)->values(), meta: [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $items->count(),
            'last_page' => (int) max(1, ceil($items->count() / $perPage)),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $mission = $this->authorizedOwnMission($request);

        return $this->respondWithData($this->governanceService->missionActivitySummary($mission));
    }

    /**
     * mission_id is non-nullable in practice for this role (StoreUserRequest
     * requires it for Head of Mission / Deputy Head of Mission at creation),
     * so findOrFail() is the only guard needed: a null or dangling
     * mission_id surfaces as a 404, not a bespoke validation branch.
     */
    private function authorizedOwnMission(Request $request): Mission
    {
        $mission = Mission::findOrFail($request->user()->mission_id);

        Gate::authorize('viewOwnActivity', $mission);

        return $mission;
    }
}
