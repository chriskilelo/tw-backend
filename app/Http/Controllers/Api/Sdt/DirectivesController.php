<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Ministry;
use App\Services\DirectiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PS-level directive views for the requesting user's own ministry, wrapping
 * App\Services\DirectiveService — same authorization boundary and wrapper
 * shape as Sdt\ReportsController (Session 26): Ministry PS (and Acting PS)
 * only, via MinistryPolicy::viewPsDashboard(), since a PS only ever has one
 * ministry.
 */
class DirectivesController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly DirectiveService $directiveService) {}

    public function overview(Request $request): JsonResponse
    {
        Gate::authorize('viewPsDashboard', Ministry::class);

        return $this->respondWithData($this->directiveService->getOverview($request->user()->ministry_id));
    }

    public function compliance(Request $request): JsonResponse
    {
        Gate::authorize('viewPsDashboard', Ministry::class);

        return $this->respondWithData($this->directiveService->getComplianceDashboard($request->user()->ministry_id));
    }
}
