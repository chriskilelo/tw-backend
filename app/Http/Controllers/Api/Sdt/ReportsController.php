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
 * FR-SDT-007 (PS-level compliance view), FR-SDT-015 (submission summary).
 * Both wrap App\Services\ReportService::getComplianceDashboard() for the
 * requesting user's own ministry, restricted the same way as
 * PsDashboardController — Ministry PS (and Acting PS) only, via
 * MinistryPolicy::viewPsDashboard() — since both endpoints sit under the
 * same PS-console authorization boundary as the rest of /sdt.
 */
class ReportsController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly ReportService $reportService) {}

    public function compliance(Request $request): JsonResponse
    {
        Gate::authorize('viewPsDashboard', Ministry::class);

        return $this->respondWithData(
            $this->reportService->getComplianceDashboard($request->user()->ministry_id, $this->resolvePeriodLabel($request))
        );
    }

    /**
     * FR-SDT-015: a condensed view of the same compliance data — counts
     * only, no per-mission breakdown.
     */
    public function submissionSummary(Request $request): JsonResponse
    {
        Gate::authorize('viewPsDashboard', Ministry::class);

        $dashboard = $this->reportService->getComplianceDashboard($request->user()->ministry_id, $this->resolvePeriodLabel($request));

        return $this->respondWithData([
            'period_label' => $dashboard['period_label'],
            'summary' => $dashboard['summary'],
        ]);
    }

    private function resolvePeriodLabel(Request $request): string
    {
        return $request->filled('period_label')
            ? (string) $request->string('period_label')
            : $this->reportService->currentSubmissionPeriod()['label'];
    }
}
