<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\KpiTarget;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-KPI-013, FR-SDT-011: the national comparison matrix — every active
 * posting of the requesting user's department against every active KPI,
 * for a quarter, a half-year (the default) or a custom range. Ministry HQ
 * Director / Ministry PS / Acting PS only, see KpiPolicy::viewComparison().
 * The department is always the requester's own, never a parameter (CLAUDE.md
 * Section 10). `cycle_label` is still accepted as an alias of `period`.
 */
class KpiComparisonController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly KpiService $kpiService) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewComparison', KpiTarget::class);

        try {
            $period = $this->kpiService->resolvePeriod(
                $request->query('period') ?? $request->query('cycle_label'),
                $request->query('from'),
                $request->query('to'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()]);
        }

        return $this->respondWithData([
            ...$this->kpiService->comparison($request->user()->ministry_id, $period),
            'options' => $this->kpiService->periodOptions(),
            'can' => [
                'set_targets' => Gate::allows('setTarget', KpiTarget::class),
                'download_report' => Gate::allows('generateReport', KpiTarget::class),
            ],
        ]);
    }
}
