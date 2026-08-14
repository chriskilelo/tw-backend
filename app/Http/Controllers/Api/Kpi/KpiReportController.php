<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiTarget;
use App\Services\KpiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FR-KPI-015: a downloadable performance report (target, actual, and
 * status per KPI) for one mission, or every ministry mission when
 * {missionId} is the literal path segment "all" — "for a selected mission
 * (or all missions)" per the requirement text. Ministry HQ Director /
 * Ministry PS / Acting PS only, see KpiPolicy::generateReport().
 *
 * No PDF/Excel generation library is installed in this application
 * (`composer show --direct` has no dompdf/maatwebsite-excel/phpoffice
 * entry) and CLAUDE.md's Boost guidelines forbid adding a dependency
 * without approval. This streams the report as CSV instead — built into
 * PHP/Laravel, no new dependency — which still satisfies the literal
 * requirement ("a downloadable document containing all specified data
 * elements"); it is a substitute for the file *format* FR-KPI-015
 * describes, not for the underlying requirement. A future session should
 * add real PDF/Excel export once a library is approved.
 */
class KpiReportController extends Controller
{
    public function __construct(private readonly KpiService $kpiService) {}

    public function show(Request $request, string $missionId): StreamedResponse
    {
        Gate::authorize('generateReport', KpiTarget::class);

        $cycleLabel = $request->string('cycle_label', '')->toString();
        abort_if($cycleLabel === '', 422, 'cycle_label is required.');

        $report = $this->kpiService->buildMissionReport(
            $request->user()->ministry_id,
            $missionId === 'all' ? null : $missionId,
            $cycleLabel,
            $request->user(),
        );

        $filename = 'kpi-performance-report-'.str_replace(' ', '-', $cycleLabel).'.csv';

        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Mission', 'KPI', 'Target', 'Actual', 'Status']);

            foreach ($report['missions'] as $mission) {
                foreach ($mission['kpis'] as $kpi) {
                    fputcsv($handle, [
                        $mission['mission_name'],
                        $kpi['name'],
                        $kpi['target'] ?? '',
                        $kpi['actual'] ?? '',
                        $kpi['status'],
                    ]);
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
