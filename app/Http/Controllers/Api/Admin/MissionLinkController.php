<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UpdateMissionLinkRequest;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\User;
use App\Services\AdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * ADR-006 / BR-004 / FR-MISS-004: sets or clears the attache a department
 * has posted to a mission. Fills the long-standing gap of having no way to
 * reassign or vacate an existing posting (Admin\MissionController could
 * only create one). A Ministry Administrator is pinned to its own
 * department's link; missions themselves stay System Administrator only.
 *
 * The (mission_id, ministry_id) unique index still enforces one attache
 * per mission per department; this endpoint updates the one row in place.
 * The change is audited by ModelObserver (MissionMinistryLink is observed).
 */
class MissionLinkController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AdministrationService $administrationService) {}

    public function update(UpdateMissionLinkRequest $request, Mission $mission): JsonResponse
    {
        Gate::authorize('managePostings', Mission::class);

        try {
            $ministryId = $this->administrationService->resolveTargetMinistryId($request->user(), $request->validated('ministry_id'));
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        $attacheId = $request->validated('active_attache_user_id');

        if ($attacheId !== null && ! $this->isPostableAttache($attacheId, $ministryId, $mission)) {
            return $this->respondWithErrors(['The posted user must be an active Ministry Attache of this department assigned to this mission.'], 422);
        }

        $link = DB::transaction(function () use ($mission, $ministryId, $attacheId): MissionMinistryLink {
            $link = MissionMinistryLink::query()
                ->where('mission_id', $mission->id)
                ->where('ministry_id', $ministryId)
                ->lockForUpdate()
                ->first()
                ?? new MissionMinistryLink(['mission_id' => $mission->id, 'ministry_id' => $ministryId]);

            $link->forceFill(['active_attache_user_id' => $attacheId])->save();

            return $link;
        });

        $link->load('activeAttache:id,full_name');

        return $this->respondWithData([
            'mission_id' => $link->mission_id,
            'ministry_id' => $link->ministry_id,
            'active_attache_user_id' => $link->active_attache_user_id,
            'active_attache' => $link->activeAttache ? ['id' => $link->activeAttache->id, 'full_name' => $link->activeAttache->full_name] : null,
        ]);
    }

    private function isPostableAttache(string $userId, string $ministryId, Mission $mission): bool
    {
        $user = User::query()->with('role')->find($userId);

        return $user !== null
            && $user->role?->name === 'Ministry Attache'
            && $user->ministry_id === $ministryId
            && $user->mission_id === $mission->id
            && $user->status !== UserStatus::Deactivated;
    }
}
