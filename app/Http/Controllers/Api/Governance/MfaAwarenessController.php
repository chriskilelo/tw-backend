<?php

namespace App\Http\Controllers\Api\Governance;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Governance\GovernanceSummaryRequest;
use App\Http\Requests\Api\Governance\MfaSubmissionLogRequest;
use App\Models\Mission;
use App\Services\GovernanceService;
use App\Support\KpiPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * FR-MFA-001 to 003: read-only, aggregate-only cross-mission and
 * cross-department activity for the MFA HQ Officer and MFA Principal
 * Secretary. Never exposes record content (FR-MFA-001 AC2): every response
 * is built from App\Services\GovernanceService's counts and, for the
 * submission log, each entry's type, date, mission and department only —
 * no record id, reference, status, officer or text. The national overview
 * (FR-MFA-003) is the Principal Secretary's alone. BasePolicy::before()
 * (CLAUDE.md Section 4, Rule 2) structurally denies any write ability for
 * these roles; only GET routes exist here.
 */
class MfaAwarenessController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly GovernanceService $governanceService) {}

    public function index(GovernanceSummaryRequest $request): JsonResponse
    {
        Gate::authorize('viewMfaAwareness', Mission::class);

        return $this->respondWithData($this->governanceService->mfaAwarenessSummary(
            $request->validated('ministry_id'),
            $this->period($request->validated('period')),
        ));
    }

    public function missionSummary(GovernanceSummaryRequest $request, Mission $mission): JsonResponse
    {
        Gate::authorize('viewMissionDrillDown', $mission);

        return $this->respondWithData($this->governanceService->missionDrillDown($mission, $request->validated('ministry_id')));
    }

    public function submissions(MfaSubmissionLogRequest $request): JsonResponse
    {
        Gate::authorize('viewMfaAwareness', Mission::class);

        $validated = $request->validated();
        $log = $this->governanceService->submissionLog(
            [
                'mission_id' => $validated['mission_id'] ?? null,
                'ministry_id' => $validated['ministry_id'] ?? null,
                'type' => $validated['type'] ?? null,
                'period' => $this->period($validated['period'] ?? null),
            ],
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
        );

        return $this->respondWithData($log->items(), meta: [
            'current_page' => $log->currentPage(),
            'per_page' => $log->perPage(),
            'total' => $log->total(),
            'last_page' => $log->lastPage(),
        ]);
    }

    public function nationalOverview(GovernanceSummaryRequest $request): JsonResponse
    {
        Gate::authorize('viewNationalOverview', Mission::class);

        return $this->respondWithData($this->governanceService->nationalOverview($this->period($request->validated('period'))));
    }

    private function period(?string $label): ?KpiPeriod
    {
        return $label === null ? null : KpiPeriod::fromLabel($label);
    }
}
