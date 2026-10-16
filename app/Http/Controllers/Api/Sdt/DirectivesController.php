<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Sdt\DirectiveOverviewRequest;
use App\Services\DirectiveService;
use Illuminate\Http\JsonResponse;

/**
 * The SDT console's directive oversight board for the viewer's own
 * department (App\Services\DirectiveService): FR-SDT-001 (the PS's national
 * directive summary), FR-SDT-008/010 (the directors' read-only oversight)
 * and FR-DIR-012. DirectiveOverviewRequest authorises
 * DirectivePolicy::viewOverview() — Ministry HQ Director, Ministry PS and
 * Acting PS — before validating. The department is always the viewer's own,
 * never a parameter.
 */
class DirectivesController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly DirectiveService $directiveService) {}

    public function overview(DirectiveOverviewRequest $request): JsonResponse
    {
        return $this->respondWithData($this->directiveService->getOverview(
            $request->user()->ministry_id,
            $request->validated(),
            $request->user(),
        ));
    }

    public function compliance(DirectiveOverviewRequest $request): JsonResponse
    {
        return $this->respondWithData($this->directiveService->getComplianceDashboard($request->user()->ministry_id));
    }
}
