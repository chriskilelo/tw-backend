<?php

namespace App\Http\Controllers\Api\Kpi;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\KpiActual;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Services\AuditService;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-KPI-008/009/010/011/016: the KPI dashboard. Directors, the PS and the
 * HRM&D Officer pick any mission of their department, or the department as
 * a whole; a Ministry Attache sees only their own mission, read-only
 * (FR-KPI-010) — asking for another mission is refused, not silently
 * swapped. Every HRM&D access is audit-logged (FR-KPI-011 AC1).
 */
class KpiDashboardController extends Controller
{
    use ApiResponds;

    public function __construct(
        private readonly KpiService $kpiService,
        private readonly AuditService $auditService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', KpiTarget::class);

        $user = $request->user();
        $isAttache = $user->role?->name === 'Ministry Attache';

        try {
            $period = $this->kpiService->resolvePeriod($request->query('period'), $request->query('from'), $request->query('to'));
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()]);
        }

        $missions = $this->kpiService->missionOptions($user->ministry_id);
        $mission = null;

        if ($isAttache) {
            abort_if($user->mission_id === null, 404, 'You are not posted to a mission.');
            abort_if($request->filled('mission_id') && $request->query('mission_id') !== $user->mission_id, 403);
            $mission = $user->mission;
            $missions = collect([$mission]);
        } elseif ($request->filled('mission_id')) {
            $mission = $missions->firstWhere('id', $request->query('mission_id'));
            abort_if($mission === null, 404);
        }

        $isHrmd = $user->role?->name === 'HRM&D Officer';
        if ($isHrmd) {
            $this->auditService->record($user, 'kpi.dashboard.accessed', 'Ministry', $user->ministry_id, [
                'period' => $period->label,
                'mission_id' => $mission?->id,
            ]);
        }

        return $this->respondWithData([
            ...$this->kpiService->dashboard($user->ministry_id, $mission, $period, withReportLinks: ! $isHrmd),
            'options' => $this->kpiService->periodOptions(),
            'missions_available' => $missions
                ->map(fn (Mission $row): array => ['id' => $row->id, 'name' => $row->name, 'city' => $row->city, 'host_country' => $row->host_country])
                ->values()
                ->all(),
            'can' => [
                'choose_mission' => ! $isAttache,
                'set_targets' => Gate::allows('setTarget', KpiTarget::class),
                'compare' => Gate::allows('viewComparison', KpiTarget::class),
                'download_report' => Gate::allows('generateReport', KpiTarget::class),
                'record_actuals' => Gate::allows('recordActual', KpiActual::class),
            ],
        ]);
    }
}
