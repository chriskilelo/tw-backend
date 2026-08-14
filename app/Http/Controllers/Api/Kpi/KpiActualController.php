<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Kpi\StoreKpiActualRequest;
use App\Http\Resources\KpiActualResource;
use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\Mission;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * FR-KPI-006 to 008 (CLAUDE.md Section 11, App\Services\KpiService::recordActual()).
 * kpi_definition_id/mission_id are resolved through ministry-scoped
 * queries (KpiDefinition) or the ministry-scoped list (Mission via
 * exists:missions,id in the request, matched against the definition's
 * ministry indirectly through ministry.scope), so a KPI outside the
 * caller's own ministry 404s rather than accepting a cross-ministry
 * actual (NFR-SEC-006) — same IDOR-guard precedent as
 * Kpi\KpiTargetController.
 */
class KpiActualController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', KpiActual::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $actuals = KpiActual::query()
            ->with(['mission', 'kpiDefinition', 'enteredBy'])
            ->when($request->filled('mission_id'), fn ($query) => $query->where('mission_id', $request->string('mission_id')))
            ->when($request->filled('kpi_definition_id'), fn ($query) => $query->where('kpi_definition_id', $request->string('kpi_definition_id')))
            ->when($request->filled('period_label'), fn ($query) => $query->where('period_label', $request->string('period_label')))
            ->orderByDesc('period_start_date')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            KpiActualResource::collection($actuals->items()),
            meta: [
                'current_page' => $actuals->currentPage(),
                'per_page' => $actuals->perPage(),
                'total' => $actuals->total(),
                'last_page' => $actuals->lastPage(),
            ],
        );
    }

    public function store(StoreKpiActualRequest $request): JsonResponse
    {
        Gate::authorize('recordActual', KpiActual::class);

        $kpiDefinition = KpiDefinition::findOrFail($request->validated('kpi_definition_id'));
        $mission = Mission::findOrFail($request->validated('mission_id'));

        $actual = $this->kpiService->recordActual(
            $kpiDefinition,
            $mission,
            $request->validated('period_label'),
            Carbon::parse($request->validated('period_start_date')),
            (float) $request->validated('actual_value'),
            $request->user(),
            'manual',
        );

        return $this->respondWithData(
            new KpiActualResource($actual->load(['mission', 'kpiDefinition', 'enteredBy'])),
            201,
        );
    }
}
