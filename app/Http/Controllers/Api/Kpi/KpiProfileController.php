<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Kpi\AssignKpiProfileRequest;
use App\Http\Requests\Api\Kpi\StoreKpiProfileRequest;
use App\Http\Resources\KpiProfileResource;
use App\Models\KpiProfile;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-KPI-002 (KPI Profiles, mission grouping). Managed by the System
 * Administrator and the department's Ministry Administrator
 * (KpiPolicy::manageProfiles()); read also by the target setters, since a
 * profile's defaults are what a mission target overrides
 * (KpiPolicy::viewProfiles(), FR-KPI-003).
 */
class KpiProfileController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewProfiles', KpiProfile::class);

        $profiles = KpiProfile::query()
            ->with(['kpiProfileDefinitions.kpiDefinition', 'kpiProfileMissions.mission'])
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->orderBy('name')
            ->get();

        return $this->respondWithData(KpiProfileResource::collection($profiles));
    }

    public function store(StoreKpiProfileRequest $request): JsonResponse
    {
        Gate::authorize('manageProfiles', KpiProfile::class);

        try {
            $profile = $this->kpiService->createProfile($request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()]);
        }

        return $this->respondWithData(
            new KpiProfileResource($profile->load(['kpiProfileDefinitions.kpiDefinition', 'kpiProfileMissions.mission'])),
            201,
        );
    }

    public function assign(AssignKpiProfileRequest $request, KpiProfile $kpiProfile): JsonResponse
    {
        Gate::authorize('manageProfiles', KpiProfile::class);

        try {
            $this->kpiService->assignProfileToMissions(
                $kpiProfile,
                $request->validated('mission_ids'),
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()]);
        }

        return $this->respondWithData(
            new KpiProfileResource($kpiProfile->fresh(['kpiProfileDefinitions.kpiDefinition', 'kpiProfileMissions.mission'])),
        );
    }
}
