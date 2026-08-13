<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Sdt\ActivateActingPsRequest;
use App\Http\Requests\Api\Sdt\DeactivateActingPsRequest;
use App\Models\Ministry;
use App\Models\User;
use App\Services\SdtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-SDT-004 to 006, BR-023: Acting PS activation/deactivation, restricted
 * to the SDT PS or a System Administrator (App\Policies\MinistryPolicy).
 * Every mutation is delegated to App\Services\SdtService, which also writes
 * the FR-SDT-005 audit log entry.
 */
class ActingPsController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly SdtService $sdtService) {}

    public function activate(ActivateActingPsRequest $request): JsonResponse
    {
        Gate::authorize('manageActingPs', Ministry::class);

        $target = User::query()->findOrFail($request->validated('user_id'));

        try {
            $this->sdtService->activateActingPs($request->user(), $target);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData([
            'ministry_id' => $target->ministry_id,
            'acting_ps_user_id' => $target->id,
            'active' => true,
        ]);
    }

    public function deactivate(DeactivateActingPsRequest $request): JsonResponse
    {
        Gate::authorize('manageActingPs', Ministry::class);

        $ministryId = $request->validated('ministry_id') ?? $request->user()->ministry_id;

        if ($ministryId === null) {
            return $this->respondWithErrors(['A ministry_id is required.'], 422);
        }

        $ministry = Ministry::query()->findOrFail($ministryId);

        try {
            $this->sdtService->deactivateActingPs($request->user(), $ministry);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData([
            'ministry_id' => $ministry->id,
            'active' => false,
        ]);
    }
}
