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
use App\Support\KpiPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-KPI-006 to 008 (App\Services\KpiService::recordActual()). Reads are
 * role-gated (KpiPolicy::viewAny()) and an attache's are pinned to their
 * own mission; a KPI outside the caller's department 404s through
 * KpiDefinition's ministry-scoped global scope (NFR-SEC-006). Manual entry
 * accepts only KPIs the engine cannot count itself, for a mission that
 * tracks them, in an open quarter (KpiService::assertActualRecordable()).
 */
class KpiActualController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', KpiActual::class);

        $user = $request->user();
        $missionId = $request->filled('mission_id') ? $request->string('mission_id')->toString() : null;

        if ($user->role?->name === 'Ministry Attache') {
            abort_if($missionId !== null && $missionId !== $user->mission_id, 403);
            $missionId = $user->mission_id;
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $actuals = KpiActual::query()
            ->with(['mission', 'kpiDefinition', 'enteredBy'])
            ->when($missionId !== null, fn ($query) => $query->where('mission_id', $missionId))
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

    /**
     * The manual entry screen for one mission and quarter (the current one
     * by default): every KPI the mission tracks, recordable or calculated.
     */
    public function entry(Request $request): JsonResponse
    {
        Gate::authorize('recordActual', KpiActual::class);

        $user = $request->user();
        $missions = $this->kpiService->missionOptions($user->ministry_id);

        if ($user->role?->name === 'Ministry Attache') {
            abort_if($user->mission_id === null, 404, 'You are not posted to a mission.');
            abort_if($request->filled('mission_id') && $request->query('mission_id') !== $user->mission_id, 403);
            $mission = $user->mission;
            $missions = collect([$mission]);
        } else {
            $mission = $request->filled('mission_id') ? $missions->firstWhere('id', $request->query('mission_id')) : $missions->first();
            abort_if($mission === null, 404);
        }

        $label = (string) $request->query('period_label', '');
        if ($label !== '' && (! KpiPeriod::isLabel($label) || ! str_starts_with($label, 'Q'))) {
            return $this->respondWithErrors(['Actuals are recorded per quarter, such as Q1 2026.']);
        }

        $quarter = $label === '' ? KpiPeriod::quarterContaining(now())->previous() : KpiPeriod::fromLabel($label);

        return $this->respondWithData([
            ...$this->kpiService->actualEntryContext($user->ministry_id, $mission, $quarter),
            'missions_available' => $missions
                ->map(fn (Mission $row): array => ['id' => $row->id, 'name' => $row->name, 'city' => $row->city, 'host_country' => $row->host_country])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreKpiActualRequest $request): JsonResponse
    {
        Gate::authorize('recordActual', KpiActual::class);

        $user = $request->user();
        $kpiDefinition = KpiDefinition::findOrFail($request->validated('kpi_definition_id'));
        $mission = Mission::findOrFail($request->validated('mission_id'));

        abort_if($user->role?->name === 'Ministry Attache' && $mission->id !== $user->mission_id, 403);

        $quarter = $request->quarter();

        try {
            $this->kpiService->assertActualRecordable($user->ministry_id, $kpiDefinition, $mission, $quarter);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()]);
        }

        $actual = $this->kpiService->recordActual(
            $kpiDefinition,
            $mission,
            $quarter->label,
            Carbon::instance($quarter->start()),
            (float) $request->validated('actual_value'),
            $user,
            'manual',
        );

        return $this->respondWithData(
            new KpiActualResource($actual->load(['mission', 'kpiDefinition', 'enteredBy'])),
            201,
        );
    }
}
