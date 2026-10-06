<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Ministry;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-SDT-001 (the PS console's compliance view, FR-RPT-018) and the
 * condensed submission summary. Both wrap App\Services\ReportService for
 * the requesting user's own ministry, restricted the same way as
 * PsDashboardController — Ministry PS (and Acting PS) only, via
 * MinistryPolicy::viewPsDashboard() — since both sit under the same
 * PS-console authorization boundary as the rest of /sdt.
 */
class ReportsController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly ReportService $reportService) {}

    public function compliance(Request $request): JsonResponse
    {
        Gate::authorize('viewPsDashboard', Ministry::class);

        return $this->respondWithData(
            $this->reportService->complianceBoard($request->user()->ministry_id, $this->requestedPeriod($request))
        );
    }

    /**
     * A condensed view of the same compliance data — counts only, no
     * per-mission breakdown.
     */
    public function submissionSummary(Request $request): JsonResponse
    {
        Gate::authorize('viewPsDashboard', Ministry::class);

        $dashboard = $this->reportService->getComplianceDashboard(
            $request->user()->ministry_id,
            $this->reportService->resolveCompliancePeriodLabel($this->requestedPeriod($request)),
        );

        return $this->respondWithData([
            'period_label' => $dashboard['period_label'],
            'period' => $dashboard['period'],
            'summary' => $dashboard['summary'],
        ]);
    }

    private function requestedPeriod(Request $request): ?string
    {
        $value = $request->query('period_label');

        return is_string($value) ? $value : null;
    }
}
