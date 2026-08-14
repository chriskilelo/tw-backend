<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Kpi\StoreKpiDefinitionRequest;
use App\Http\Requests\Api\Kpi\UpdateKpiDefinitionRequest;
use App\Http\Resources\KpiDefinitionResource;
use App\Models\KpiDefinition;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-KPI-001 (KPI Library). System Administrator only, including GET —
 * see KpiPolicy::manageDefinitions().
 */
class KpiDefinitionController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manageDefinitions', KpiDefinition::class);

        $definitions = KpiDefinition::query()
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->orderBy('name')
            ->get();

        return $this->respondWithData(KpiDefinitionResource::collection($definitions));
    }

    public function store(StoreKpiDefinitionRequest $request): JsonResponse
    {
        Gate::authorize('manageDefinitions', KpiDefinition::class);

        $definition = $this->kpiService->defineKpi($request->validated(), $request->user());

        return $this->respondWithData(new KpiDefinitionResource($definition), 201);
    }

    public function update(UpdateKpiDefinitionRequest $request, KpiDefinition $kpiDefinition): JsonResponse
    {
        Gate::authorize('manageDefinitions', KpiDefinition::class);

        $kpiDefinition->fill($request->validated())->save();

        return $this->respondWithData(new KpiDefinitionResource($kpiDefinition->fresh()));
    }
}
