<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreMissionRequest;
use App\Http\Requests\Api\Admin\UpdateMissionRequest;
use App\Http\Resources\MissionResource;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Session 07 task 4. GET is open to any authenticated user (mission
 * dropdowns); write actions are System Administrator only, enforced via
 * MissionPolicy.
 */
class MissionController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Mission::class);

        $isSystemAdministrator = $request->user()?->role?->name === 'System Administrator';

        $missions = Mission::query()
            ->when($isSystemAdministrator, fn ($query) => $query->with('missionMinistryLinks'))
            ->orderBy('name')
            ->get();

        return $this->respondWithData(MissionResource::collection($missions));
    }

    public function store(StoreMissionRequest $request): JsonResponse
    {
        Gate::authorize('create', Mission::class);

        $mission = Mission::create($request->only(['name', 'city', 'host_country', 'time_zone']));

        $this->auditService->record(
            $request->user(),
            'mission.created',
            Mission::class,
            $mission->id,
            $request->only(['name', 'city', 'host_country', 'time_zone']),
            $request->ip(),
        );

        return $this->respondWithData(new MissionResource($mission), 201);
    }

    public function update(UpdateMissionRequest $request, Mission $mission): JsonResponse
    {
        Gate::authorize('update', $mission);

        $changes = $request->only(['name', 'city', 'host_country', 'time_zone']);

        if ($changes !== []) {
            $mission->forceFill($changes)->save();
        }

        if ($request->filled('ministry_id')) {
            $assignmentError = $this->assignAttache($mission, $request);

            if ($assignmentError !== null) {
                return $assignmentError;
            }
        }

        $this->auditService->record(
            $request->user(),
            'mission.updated',
            Mission::class,
            $mission->id,
            $request->only(['name', 'city', 'host_country', 'time_zone', 'ministry_id', 'active_attache_user_id']),
            $request->ip(),
        );

        return $this->respondWithData(new MissionResource($mission->fresh('missionMinistryLinks')));
    }

    public function deactivate(Request $request, Mission $mission): JsonResponse
    {
        Gate::authorize('deactivate', $mission);

        $mission->forceFill(['active' => false])->save();

        $this->auditService->record($request->user(), 'mission.deactivated', Mission::class, $mission->id, null, $request->ip());

        return $this->respondWithData(new MissionResource($mission->fresh()));
    }

    /**
     * Establishes the mission_ministry_links row for the given ministry.
     * BR-004 is enforced at the database layer by the unique index on
     * (mission_id, ministry_id); a second attempt to establish a link for
     * a pairing that already exists is rejected here as a 422 rather than
     * silently overwriting the existing link.
     */
    private function assignAttache(Mission $mission, Request $request): ?JsonResponse
    {
        try {
            // Wrapped in its own transaction so a unique-violation rollback
            // only unwinds to a savepoint, not the outer request transaction.
            DB::transaction(function () use ($mission, $request): void {
                MissionMinistryLink::query()->create([
                    'mission_id' => $mission->id,
                    'ministry_id' => $request->input('ministry_id'),
                    'active_attache_user_id' => $request->input('active_attache_user_id'),
                ]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            return $this->respondWithErrors(
                ['A mission may have only one active Ministry Attache per ministry (BR-004).'],
                422,
            );
        }

        return null;
    }
}
