<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Kpi\StoreKpiTargetRequest;
use App\Http\Resources\KpiTargetResource;
use App\Models\KpiDefinition;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * FR-KPI-004, FR-KPI-005, BR-019 (versioned performance-cycle targets).
 * Ministry HQ Director and Ministry PS (and Acting PS) only — see
 * KpiPolicy::setTarget(). kpi_definition_id is resolved through
 * KpiDefinition's own ministry-scoped global scope (bound by the
 * ministry.scope route middleware), so a KPI outside the setter's ministry
 * 404s rather than silently accepting a cross-ministry target
 * (NFR-SEC-006), matching the IDOR-guard precedent already established for
 * report data rows.
 */
class KpiTargetController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function store(StoreKpiTargetRequest $request): JsonResponse
    {
        Gate::authorize('setTarget', KpiTarget::class);

        $kpiDefinition = KpiDefinition::findOrFail($request->validated('kpi_definition_id'));
        $mission = Mission::findOrFail($request->validated('mission_id'));

        $target = $this->kpiService->setTarget(
            $kpiDefinition,
            $mission,
            $request->validated('performance_cycle_label'),
            Carbon::parse($request->validated('cycle_start_date')),
            (float) $request->validated('target_value'),
            $request->user(),
        );

        return $this->respondWithData(
            new KpiTargetResource($target->load(['mission', 'kpiDefinition', 'setBy'])),
            201,
        );
    }
}
