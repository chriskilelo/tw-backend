<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Ministry;
use App\Services\SdtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-SDT-012, FR-SDT-013: the HQ Trade Officer's portfolio workspace,
 * filterable by mission_id and/or status. Ministry HQ Officer only.
 * Ministry scoping is applied automatically by the ministry.scope route
 * middleware plus the Alert/Inquiry/Directive models' global scope.
 */
class HqWorkspaceController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly SdtService $sdtService) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewHqWorkspace', Ministry::class);

        return $this->respondWithData(
            $this->sdtService->hqWorkspace($request->user(), $request->only(['mission_id', 'status']))
        );
    }
}
