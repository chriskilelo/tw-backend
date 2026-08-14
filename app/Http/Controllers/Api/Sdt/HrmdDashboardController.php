<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\KpiTarget;
use App\Models\User;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-SDT-016, FR-SDT-017, FR-KPI-011: the HRM&D Officer's dedicated,
 * read-only KPI dashboard and per-attache performance summary. HRM&D
 * Officer only — see KpiPolicy::viewHrmdDashboard()/generateAttacheSummary()
 * and BasePolicy::before()'s FR-SDT-018 restriction (this policy is the
 * only one an HRM&D Officer may reach at all). Every access is
 * audit-logged by App\Services\KpiService (FR-KPI-011 AC1), not here.
 */
class HrmdDashboardController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewHrmdDashboard', KpiTarget::class);

        $cycleLabel = $request->string('cycle_label', '')->toString();
        abort_if($cycleLabel === '', 422, 'cycle_label is required.');

        return $this->respondWithData($this->kpiService->hrmdDashboard($request->user(), $cycleLabel));
    }

    /**
     * FR-SDT-017: $userId is looked up directly (not implicit route model
     * binding) so a cross-ministry attache id can be rejected with an
     * explicit 404 rather than accidentally resolving via User's own
     * withTrashed() route-binding override (Session 17), which has no
     * ministry awareness at all — same IDOR-guard reasoning as the
     * notification-ownership and report-data-row precedents elsewhere in
     * this codebase.
     */
    public function attacheSummary(Request $request, string $userId): JsonResponse
    {
        Gate::authorize('generateAttacheSummary', KpiTarget::class);

        $periodLabel = $request->string('period_label', '')->toString();
        abort_if($periodLabel === '', 422, 'period_label is required.');

        $officer = $request->user();
        $attache = User::query()->findOrFail($userId);

        abort_unless($attache->ministry_id === $officer->ministry_id, 404);

        return $this->respondWithData(
            $this->kpiService->attachePerformanceSummary($officer, $attache, $periodLabel),
        );
    }
}
