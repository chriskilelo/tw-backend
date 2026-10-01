<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Kpi\SaveKpiTargetsRequest;
use App\Http\Requests\Api\Kpi\StoreKpiTargetRequest;
use App\Http\Resources\KpiTargetResource;
use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Services\KpiService;
use App\Support\KpiPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-KPI-003, FR-KPI-004, FR-KPI-005, BR-019 (versioned performance-cycle
 * targets). Ministry HQ Director, Ministry PS and Acting PS only — see
 * KpiPolicy::setTarget()/viewTargets(). KPI definitions and profiles are
 * resolved through their ministry-scoped global scope (bound by the
 * ministry.scope route middleware), so another department's KPI or profile
 * 404s rather than accepting a cross-ministry target (NFR-SEC-006); a
 * mission must be an active posting of the setter's department.
 */
class KpiTargetController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    /**
     * The target planner for one half-yearly cycle (the current one by
     * default): every mission x KPI with the target in force and its source.
     */
    public function plan(Request $request): JsonResponse
    {
        Gate::authorize('viewTargets', KpiTarget::class);

        $label = (string) $request->query('cycle_label', '');
        if ($label !== '' && ! KpiPeriod::isHalfLabel($label)) {
            return $this->respondWithErrors(['Targets are set for a half-yearly performance cycle, such as H1 2026.']);
        }

        $cycle = $label === '' ? KpiPeriod::halfContaining(now()) : KpiPeriod::fromLabel($label);

        return $this->respondWithData([
            ...$this->kpiService->targetPlan($request->user()->ministry_id, $cycle),
            'can' => ['set_targets' => Gate::allows('setTarget', KpiTarget::class)],
        ]);
    }

    public function store(StoreKpiTargetRequest $request): JsonResponse
    {
        Gate::authorize('setTarget', KpiTarget::class);

        $kpiDefinition = KpiDefinition::findOrFail($request->validated('kpi_definition_id'));
        $mission = $request->filled('mission_id') ? Mission::findOrFail($request->validated('mission_id')) : null;
        $profile = $mission === null ? KpiProfile::findOrFail($request->validated('kpi_profile_id')) : null;

        try {
            $target = $this->kpiService->setTargetFor(
                $request->user()->ministry_id,
                $kpiDefinition,
                $mission,
                $profile,
                $request->cycle(),
                (float) $request->validated('target_value'),
                $request->user(),
                $request->validated('note'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()]);
        }

        return $this->respondWithData(
            new KpiTargetResource($target->load($mission !== null ? ['mission', 'kpiDefinition', 'setBy'] : ['kpiProfile', 'kpiDefinition', 'setBy'])),
            201,
        );
    }

    /**
     * Saves the planner's edits in one transaction and returns the
     * refreshed plan.
     */
    public function batch(SaveKpiTargetsRequest $request): JsonResponse
    {
        Gate::authorize('setTarget', KpiTarget::class);

        $ministryId = $request->user()->ministry_id;
        $cycle = $request->cycle();

        try {
            $result = $this->kpiService->saveTargets($ministryId, $cycle, $request->validated('targets'), $request->user(), $request->validated('note'));
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()]);
        }

        return $this->respondWithData([
            ...$result,
            'plan' => [
                ...$this->kpiService->targetPlan($ministryId, $cycle),
                'can' => ['set_targets' => true],
            ],
        ]);
    }

    /**
     * FR-KPI-004 AC1: every version of one mission's (or profile's) target
     * for a KPI, across all cycles.
     */
    public function history(Request $request): JsonResponse
    {
        Gate::authorize('viewTargets', KpiTarget::class);

        $request->validate([
            'kpi_definition_id' => ['required', 'uuid'],
            'mission_id' => ['required_without:kpi_profile_id', 'nullable', 'uuid'],
            'kpi_profile_id' => ['nullable', 'uuid'],
        ]);

        $kpiDefinition = KpiDefinition::findOrFail($request->query('kpi_definition_id'));
        $mission = null;
        $profile = null;

        if ($request->filled('mission_id')) {
            $mission = $this->kpiService->missionOptions($request->user()->ministry_id)->firstWhere('id', $request->query('mission_id'));
            abort_if($mission === null, 404);
        } else {
            $profile = KpiProfile::findOrFail($request->query('kpi_profile_id'));
        }

        return $this->respondWithData($this->kpiService->targetHistory($kpiDefinition, $mission, $profile));
    }
}
