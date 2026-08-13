<?php

namespace App\Http\Controllers\Api\Governance;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Mission;
use App\Services\GovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * FR-MFA-001 to 003: read-only, aggregate-only cross-mission and
 * cross-ministry activity for MFA HQ Officer and MFA Principal Secretary.
 * Never exposes full record content (FR-MFA-001 AC2) — every response is
 * built from App\Services\GovernanceService's normalized summary counts,
 * not the underlying Alert/Inquiry/Directive/PeriodicReport rows.
 * BasePolicy::before() (CLAUDE.md Section 4, Rule 2) structurally denies
 * any non-GET ability for these roles before a method here could run.
 */
class MfaAwarenessController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly GovernanceService $governanceService) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewMfaAwareness', Mission::class);

        return $this->respondWithData($this->governanceService->mfaAwarenessSummary());
    }

    public function missionSummary(Mission $mission): JsonResponse
    {
        Gate::authorize('viewMissionDrillDown', $mission);

        return $this->respondWithData($this->governanceService->missionDrillDown($mission));
    }

    public function nationalOverview(): JsonResponse
    {
        Gate::authorize('viewNationalOverview', Mission::class);

        return $this->respondWithData($this->governanceService->nationalOverview());
    }
}
