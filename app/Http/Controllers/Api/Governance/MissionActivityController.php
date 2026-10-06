<?php

namespace App\Http\Controllers\Api\Governance;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Governance\GovernanceSummaryRequest;
use App\Http\Requests\Api\Governance\MissionActivityFeedRequest;
use App\Models\Mission;
use App\Services\GovernanceService;
use App\Support\KpiPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-HOM-001 to 003: the read-only mission activity feed and summary
 * metrics for the Head of Mission and Deputy Head of Mission, always for
 * their own assigned mission — the mission is never a request parameter, so
 * it cannot be swapped for another. MissionPolicy::viewOwnActivity() denies
 * every other role, and an oversight account without a mission, with a 403.
 * BasePolicy::before() (CLAUDE.md Section 4, Rule 2) structurally denies
 * any write ability for these roles; only GET routes exist here.
 *
 * Opening a feed item's full content (FR-HOM-001 AC2) goes through the
 * owning engine's detail endpoint, where AlertPolicy, InquiryPolicy and
 * ReportPolicy confine these roles to their own mission.
 */
class MissionActivityController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly GovernanceService $governanceService) {}

    public function index(MissionActivityFeedRequest $request): JsonResponse
    {
        $mission = $this->authorizedOwnMission($request);
        $validated = $request->validated();

        $feed = $this->governanceService->missionActivityFeed(
            $mission,
            [
                'ministry_id' => $validated['ministry_id'] ?? null,
                'type' => $validated['type'] ?? null,
                'status' => $validated['status'] ?? null,
                'period' => isset($validated['period']) ? KpiPeriod::fromLabel($validated['period']) : null,
            ],
            $validated['sort'] ?? '-date',
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
        );

        return $this->respondWithData($feed->items(), meta: [
            'current_page' => $feed->currentPage(),
            'per_page' => $feed->perPage(),
            'total' => $feed->total(),
            'last_page' => $feed->lastPage(),
        ]);
    }

    public function summary(GovernanceSummaryRequest $request): JsonResponse
    {
        $mission = $this->authorizedOwnMission($request);

        return $this->respondWithData(
            $this->governanceService->missionActivitySummary($mission, $request->validated('ministry_id'))
        );
    }

    private function authorizedOwnMission(Request $request): Mission
    {
        $missionId = $request->user()->mission_id;
        $mission = $missionId === null ? null : Mission::query()->find($missionId);

        Gate::authorize('viewOwnActivity', [Mission::class, $mission]);

        return $mission;
    }
}
