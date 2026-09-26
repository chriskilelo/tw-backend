<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Sdt\ActivateDesignatedDeputyRequest;
use App\Http\Requests\Api\Sdt\DeactivateDesignatedDeputyRequest;
use App\Models\Ministry;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-SDT-003, FR-ALERT-008: the Designated Deputy fallback switch, which had
 * a service method but no endpoint until ADR-006. Gated by
 * MinistryPolicy::manageDesignatedDeputy (Ministry PS, Acting PS, Ministry
 * Administrator, System Administrator); AlertService pins every actor but a
 * System Administrator to its own department. Mirrors ActingPsController.
 */
class DesignatedDeputyController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AlertService $alertService) {}

    public function activate(ActivateDesignatedDeputyRequest $request): JsonResponse
    {
        Gate::authorize('manageDesignatedDeputy', Ministry::class);

        $deputy = User::query()->findOrFail($request->validated('user_id'));

        try {
            $this->alertService->activateDesignatedDeputy($request->user(), $deputy);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData([
            'ministry_id' => $deputy->ministry_id,
            'designated_deputy_user_id' => $deputy->id,
            'active' => true,
        ]);
    }

    public function deactivate(DeactivateDesignatedDeputyRequest $request): JsonResponse
    {
        Gate::authorize('manageDesignatedDeputy', Ministry::class);

        $ministryId = $request->validated('ministry_id') ?? $request->user()->ministry_id;

        if ($ministryId === null) {
            return $this->respondWithErrors(['A ministry_id is required.'], 422);
        }

        $ministry = Ministry::query()->findOrFail($ministryId);

        try {
            $this->alertService->deactivateDesignatedDeputy($request->user(), $ministry);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData([
            'ministry_id' => $ministry->id,
            'active' => false,
        ]);
    }
}
