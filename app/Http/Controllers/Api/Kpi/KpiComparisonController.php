<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\KpiTarget;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-KPI-013, FR-SDT-011: the national comparison matrix — every
 * ministry-linked mission x every active KPI, for one cycle label.
 * Ministry HQ Director / Ministry PS / Acting PS only, see
 * KpiPolicy::viewComparison(). $ministryId is always the requesting
 * user's own ministry, never a client-supplied query parameter (CLAUDE.md
 * Section 10's ministry-scoping convention).
 */
class KpiComparisonController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewComparison', KpiTarget::class);

        $cycleLabel = $request->string('cycle_label', '')->toString();
        abort_if($cycleLabel === '', 422, 'cycle_label is required.');

        return $this->respondWithData(
            $this->kpiService->buildComparisonMatrix($request->user()->ministry_id, $cycleLabel),
        );
    }
}
