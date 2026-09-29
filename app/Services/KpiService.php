<?php

namespace App\Services;

use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\KpiProfileMission;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-KPI-001 to 016, FR-SDT-016 to 018: business
 * logic for the KPI Framework Engine (definitions, profiles, mission
 * assignment, targets, actuals, aggregation, comparison, and the HRM&D
 * read-only dashboard). Controllers stay thin and delegate every mutation
 * (and every HRM&D read, which FR-KPI-011 AC1 requires to be audit-logged)
 * here.
 */
class KpiService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * FR-KPI-001. calculation_method is either 'auto' (derived from the
     * data_source field, e.g. a Layer 2 engine metric) or 'manual'.
     *
     * @param  array<string, mixed>  $data  Already validated by StoreKpiDefinitionRequest.
     */
    public function defineKpi(array $data, User $admin): KpiDefinition
    {
        // KpiDefinition is registered on App\Observers\ModelObserver
        // (AppServiceProvider::boot()), so this create() call is already
        // written to audit_logs as `kpi_definition.created` without a
        // manual AuditService call here.
        return KpiDefinition::create([
            'ministry_id' => $data['ministry_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? null,
            'calculation_method' => $data['calculation_method'],
            'data_source' => $data['data_source'] ?? null,
            'reporting_frequency' => $data['reporting_frequency'],
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * FR-KPI-002: a reusable, named group of KPI definitions.
     *
     * @param  array<string, mixed>  $data  Already validated by StoreKpiProfileRequest.
     */
    public function createProfile(array $data, User $admin): KpiProfile
    {
        return DB::transaction(function () use ($data) {
            $profile = KpiProfile::create([
                'ministry_id' => $data['ministry_id'],
                'name' => $data['name'],
            ]);

            foreach ($data['kpi_definition_ids'] ?? [] as $kpiDefinitionId) {
                $profile->kpiProfileDefinitions()->create([
                    'kpi_definition_id' => $kpiDefinitionId,
                ]);
            }

            return $profile;
        });
    }

    /**
     * FR-KPI-002 (mission-grouping): replaces the full set of missions a
     * profile is assigned to with $missionIds — repeating the same call
     * with the same ids is a no-op on the second call (API-001 Section 10
     * idempotency convention), not an additive append.
     *
     * @param  array<int, string>  $missionIds
     */
    public function assignProfileToMissions(KpiProfile $profile, array $missionIds, User $admin): void
    {
        DB::transaction(function () use ($profile, $missionIds, $admin) {
            $profile->kpiProfileMissions()
                ->whereNotIn('mission_id', $missionIds)
                ->delete();

            $existingMissionIds = $profile->kpiProfileMissions()->pluck('mission_id')->all();

            foreach (array_diff($missionIds, $existingMissionIds) as $missionId) {
                KpiProfileMission::create([
                    'kpi_profile_id' => $profile->id,
                    'mission_id' => $missionId,
                    'assigned_by_user_id' => $admin->id,
                ]);
            }
        });
    }

    /**
     * BR-019, FR-KPI-004, FR-KPI-005: targets are versioned by performance
     * cycle. Setting a target for a cycle+kpi+mission that already has one
     * NEVER updates the prior row — it always inserts a new row carrying
     * the later cycle_start_date, leaving every prior cycle's target
     * untouched.
     */
    public function setTarget(
        KpiDefinition $kpi,
        Mission $mission,
        string $cycleLabel,
        Carbon $cycleStart,
        float $targetValue,
        User $setter,
    ): KpiTarget {
        // KpiTarget is registered on ModelObserver, so this create() is
        // already audit-logged with the setting user and timestamp
        // (FR-KPI-005 AC1) via the generic kpi_target.created row.
        return KpiTarget::create([
            'mission_id' => $mission->id,
            'kpi_definition_id' => $kpi->id,
            'performance_cycle_label' => $cycleLabel,
            'cycle_start_date' => $cycleStart,
            'target_value' => $targetValue,
            'set_by_user_id' => $setter->id,
        ]);
    }

    /**
     * FR-KPI-006, FR-KPI-007: records (or, for a mission/kpi/quarter
     * already recorded, updates in place — TC-FR-KPI-016-C exercises
     * exactly this re-recording path) the actual value for one mission,
     * one KPI, and one quarter. Used both by the manual-entry API path
     * (Ministry Attache / Ministry HQ Officer, calculation_type='manual')
     * and by the foams:compute-kpi-actuals scheduled command
     * (calculation_type='auto'). Deliberately takes a nullable $actor,
     * not the literal task signature's non-nullable `User $actor`: an
     * auto-computed actual has no acting user, and
     * kpi_actuals.entered_by_user_id is documented (CLAUDE.md Section 6)
     * as "null when calculation_type = 'auto'".
     */
    public function recordActual(
        KpiDefinition $kpi,
        Mission $mission,
        string $periodLabel,
        Carbon $periodStart,
        float $value,
        ?User $actor,
        string $calculationType,
    ): KpiActual {
        return KpiActual::withoutGlobalScopes()->updateOrCreate(
            [
                'mission_id' => $mission->id,
                'kpi_definition_id' => $kpi->id,
                'period_start_date' => $periodStart->toDateString(),
            ],
            [
                'period_label' => $periodLabel,
                'actual_value' => $value,
                'entered_by_user_id' => $actor?->id,
                'calculation_type' => $calculationType,
            ],
        );
    }

    /**
     * FR-KPI-016: aggregates Q1+Q2 (Jul-Dec) into H1 and Q3+Q4 (Jan-Jun)
     * into H2 for the given calendar $year, following the same
     * quarter/year labelling convention as
     * ReportService::periodLabelFor() (quarter number keyed by start
     * month, label year = that start month's own calendar year — so, as
     * with report periods, "H2 {$year}" and "H1 {$year}" are NOT the two
     * halves of one fiscal year; each half is independently labelled by
     * its own quarters' start-month year).
     *
     * Inclusion is keyed purely on period_start_date, never on
     * created_at/updated_at — a KpiActual entered late for a quarter
     * (e.g. after that quarter's own reporting deadline) still belongs to
     * that quarter's period_start_date and is included exactly like an
     * on-time one; there is no timestamp-based cutoff filter to exclude
     * it (TC-FR-KPI-016-B).
     *
     * @return array{H1: array<string, mixed>, H2: array<string, mixed>}
     */
    public function computeAggregation(KpiDefinition $kpi, Mission $mission, int $year): array
    {
        return [
            'H1' => $this->aggregateHalf($kpi, $mission, $year, 1),
            'H2' => $this->aggregateHalf($kpi, $mission, $year, 2),
        ];
    }

    /**
     * FR-KPI-013: every ministry-linked mission x every active KPI
     * definition for one cycle label — quarterly ("Q1 2027") or
     * half-yearly ("H1 2027"), per FR-KPI-013's "both quarterly and
     * half-yearly views" requirement. The task's literal signature types
     * $ministryId as `int`; kept as `string` (uuid) to match the actual
     * schema and every other service method's ministry_id parameter in
     * this codebase.
     *
     * @return array{cycle_label: string, missions: array<int, array<string, mixed>>}
     */
    public function buildComparisonMatrix(string $ministryId, string $cycleLabel): array
    {
        $definitions = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return [
            'cycle_label' => $cycleLabel,
            'missions' => $this->ministryMissions($ministryId)
                ->map(fn (Mission $mission): array => $this->missionKpiRow($mission, $definitions, $cycleLabel))
                ->values()
                ->all(),
        ];
    }

    /**
     * FR-KPI-015: target, actual, and status per KPI for one mission (or,
     * when $missionId is null, every mission in the ministry) for one
     * cycle label — the data set behind the downloadable performance
     * report. Building this is deliberately separated from the
     * controller's choice of downloadable file format (see
     * Kpi\KpiReportController's docblock for why that is CSV, not
     * PDF/Excel).
     *
     * @return array{ministry_id: string, cycle_label: string, generated_by: array<string, mixed>, generated_at: string, missions: array<int, array<string, mixed>>}
     */
    public function buildMissionReport(string $ministryId, ?string $missionId, string $cycleLabel, User $generatedBy): array
    {
        $definitions = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $missions = $this->ministryMissions($ministryId)
            ->when($missionId !== null, fn ($missions) => $missions->where('id', $missionId));

        return [
            'ministry_id' => $ministryId,
            'cycle_label' => $cycleLabel,
            'generated_by' => ['id' => $generatedBy->id, 'full_name' => $generatedBy->full_name],
            'generated_at' => now()->toIso8601String(),
            'missions' => $missions
                ->map(fn (Mission $mission): array => $this->missionKpiRow($mission, $definitions, $cycleLabel))
                ->values()
                ->all(),
        ];
    }

    /**
     * FR-SDT-016, FR-KPI-011: HRM&D read-only KPI comparison across every
     * mission in the officer's own ministry, for one cycle label. FR-KPI-011
     * AC1 requires every HRM&D access to be logged to the audit trail —
     * ModelObserver only logs model mutations, not reads, so this is
     * logged explicitly here rather than relying on it.
     *
     * @return array{cycle_label: string, missions: array<int, array<string, mixed>>}
     */
    public function hrmdDashboard(User $officer, string $cycleLabel): array
    {
        $matrix = $this->buildComparisonMatrix($officer->ministry_id, $cycleLabel);

        $this->auditService->record(
            $officer,
            'kpi.hrmd_dashboard.accessed',
            'Ministry',
            $officer->ministry_id,
            ['cycle_label' => $cycleLabel],
        );

        return $matrix;
    }

    /**
     * FR-SDT-017, FR-KPI-011 AC2: a single named attache's KPI performance
     * summary for one cycle label, for formal HR evaluation. Every
     * generation is audit-logged (FR-KPI-011 AC1), same as
     * hrmdDashboard(). The controller is responsible for confirming
     * $attache actually belongs to $officer's own ministry before calling
     * this (the IDOR-guard 404 pattern already established for
     * ministry-scoped lookups elsewhere in this codebase) — $attache is
     * not itself a ministry-scoped model (User carries no
     * HasMinistryScope), so this method does not re-check it.
     *
     * @return array{attache: array<string, mixed>, cycle_label: string, kpis: array<int, array<string, mixed>>}
     */
    public function attachePerformanceSummary(User $officer, User $attache, string $cycleLabel): array
    {
        if ($attache->mission === null) {
            throw new InvalidArgumentException('The selected user is not assigned to a mission.');
        }

        $definitions = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $attache->ministry_id)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $row = $this->missionKpiRow($attache->mission, $definitions, $cycleLabel);

        $summary = [
            'attache' => [
                'id' => $attache->id,
                'full_name' => $attache->full_name,
                'mission' => ['id' => $attache->mission->id, 'name' => $attache->mission->name],
            ],
            'cycle_label' => $cycleLabel,
            'kpis' => $row['kpis'],
        ];

        $this->auditService->record(
            $officer,
            'kpi.hrmd_attache_summary.generated',
            'User',
            $attache->id,
            ['cycle_label' => $cycleLabel],
        );

        return $summary;
    }

    /**
     * FR-KPI-010: one mission's target, actual and status for every active
     * KPI in its ministry, for one cycle label — the director view's metrics
     * (FR-KPI-008) restricted to a single mission, read-only. Not
     * audit-logged: FR-KPI-011's access logging covers HRM&D access only.
     *
     * @return array<int, array{kpi_definition_id: string, name: string, target: ?float, actual: ?float, status: string}>
     */
    public function missionPerformance(Mission $mission, string $ministryId, string $cycleLabel): array
    {
        $definitions = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return $this->missionKpiRow($mission, $definitions, $cycleLabel)['kpis'];
    }

    /**
     * Performance-status counts per ministry mission for one cycle label.
     * Same targets, actuals and thresholds as buildComparisonMatrix(), but
     * loaded in two bulk queries rather than two or three per KPI per
     * mission, since the leadership dashboard only needs the counts.
     *
     * @return array<int, array{mission_id: string, mission_name: string, counts: array<string, int>, total: int}>
     */
    public function missionStatusSummary(string $ministryId, string $cycleLabel): array
    {
        $definitionIds = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('active', true)
            ->pluck('id');

        // Ascending order so keyBy() keeps the latest target per KPI and mission,
        // matching kpiTargetVsActual()'s latest('created_at').
        $targets = KpiTarget::query()
            ->withoutGlobalScopes()
            ->whereIn('kpi_definition_id', $definitionIds)
            ->where('performance_cycle_label', $cycleLabel)
            ->orderBy('created_at')
            ->get()
            ->keyBy(fn (KpiTarget $target): string => $target->kpi_definition_id.'|'.$target->mission_id);

        $periodStarts = array_map(fn (Carbon $start): string => $start->toDateString(), $this->cycleQuarterStarts($cycleLabel));

        $actuals = KpiActual::query()
            ->withoutGlobalScopes()
            ->whereIn('kpi_definition_id', $definitionIds)
            ->whereIn('period_start_date', $periodStarts)
            ->get()
            ->unique(fn (KpiActual $actual): string => $actual->kpi_definition_id.'|'.$actual->mission_id.'|'.$actual->period_start_date->toDateString())
            ->groupBy(fn (KpiActual $actual): string => $actual->kpi_definition_id.'|'.$actual->mission_id)
            ->map(fn (Collection $rows): float => (float) $rows->sum(fn (KpiActual $actual): float => (float) $actual->actual_value));

        return $this->ministryMissions($ministryId)
            ->map(function (Mission $mission) use ($definitionIds, $targets, $actuals): array {
                $counts = ['on_track' => 0, 'at_risk' => 0, 'below_target' => 0, 'no_target' => 0, 'no_data' => 0];

                foreach ($definitionIds as $definitionId) {
                    $key = $definitionId.'|'.$mission->id;
                    $targetValue = $targets->get($key)?->target_value;

                    $counts[$this->performanceStatus($targetValue !== null ? (float) $targetValue : null, $actuals->get($key))]++;
                }

                return [
                    'mission_id' => $mission->id,
                    'mission_name' => $mission->name,
                    'counts' => $counts,
                    'total' => $definitionIds->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The quarter start dates a cycle label covers: one for "Q1 2027", two
     * for "H1 2027" — the same quarters actualForCycle() reads.
     *
     * @return array<int, Carbon>
     */
    private function cycleQuarterStarts(string $cycleLabel): array
    {
        [$unit, $yearPart] = array_pad(explode(' ', $cycleLabel, 2), 2, null);
        $year = (int) $yearPart;

        $quarterNumbers = match ($unit) {
            'H1' => [1, 2],
            'H2' => [3, 4],
            default => [(int) ltrim((string) $unit, 'Q')],
        };

        return array_map(fn (int $quarterNumber): Carbon => $this->quarterStart($year, $quarterNumber), $quarterNumbers);
    }

    /**
     * @return array{label: string, quarters: array<string, ?float>, total: ?float, quarters_reported: int}
     */
    private function aggregateHalf(KpiDefinition $kpi, Mission $mission, int $year, int $half): array
    {
        $quarterNumbers = $half === 1 ? [1, 2] : [3, 4];
        $quarters = [];
        $total = null;
        $quartersReported = 0;

        foreach ($quarterNumbers as $quarterNumber) {
            $label = $this->quarterLabel($year, $quarterNumber);
            $value = $this->actualValueFor($kpi, $mission, $this->quarterStart($year, $quarterNumber));

            $quarters[$label] = $value;

            if ($value !== null) {
                $total = ($total ?? 0.0) + $value;
                $quartersReported++;
            }
        }

        return [
            'label' => "H{$half} {$year}",
            'quarters' => $quarters,
            'total' => $total,
            'quarters_reported' => $quartersReported,
        ];
    }

    /**
     * @return array{mission_id: string, mission_name: string, kpis: array<int, array<string, mixed>>}
     */
    private function missionKpiRow(Mission $mission, Collection $definitions, string $cycleLabel): array
    {
        return [
            'mission_id' => $mission->id,
            'mission_name' => $mission->name,
            'kpis' => $definitions
                ->map(fn (KpiDefinition $kpi): array => $this->kpiTargetVsActual($kpi, $mission, $cycleLabel))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{kpi_definition_id: string, name: string, target: ?float, actual: ?float, status: string}
     */
    private function kpiTargetVsActual(KpiDefinition $kpi, Mission $mission, string $cycleLabel): array
    {
        $target = KpiTarget::query()
            ->withoutGlobalScopes()
            ->where('kpi_definition_id', $kpi->id)
            ->where('mission_id', $mission->id)
            ->where('performance_cycle_label', $cycleLabel)
            ->latest('created_at')
            ->first();

        $targetValue = $target?->target_value !== null ? (float) $target->target_value : null;
        $actualValue = $this->actualForCycle($kpi, $mission, $cycleLabel);

        return [
            'kpi_definition_id' => $kpi->id,
            'name' => $kpi->name,
            'target' => $targetValue,
            'actual' => $actualValue,
            'status' => $this->performanceStatus($targetValue, $actualValue),
        ];
    }

    /**
     * Resolves a cycle label ("Q1 2027" or "H1 2027") to its actual value:
     * a quarterly label reads the single matching KpiActual directly; a
     * half-yearly label sums its two constituent quarters via
     * aggregateHalf(), the same aggregation computeAggregation() exposes
     * directly for a full-year, both-halves view.
     */
    private function actualForCycle(KpiDefinition $kpi, Mission $mission, string $cycleLabel): ?float
    {
        [$unit, $yearPart] = array_pad(explode(' ', $cycleLabel, 2), 2, null);
        $year = (int) $yearPart;

        if ($unit === 'H1' || $unit === 'H2') {
            return $this->aggregateHalf($kpi, $mission, $year, $unit === 'H1' ? 1 : 2)['total'];
        }

        $quarterNumber = (int) ltrim((string) $unit, 'Q');

        return $this->actualValueFor($kpi, $mission, $this->quarterStart($year, $quarterNumber));
    }

    private function actualValueFor(KpiDefinition $kpi, Mission $mission, Carbon $periodStart): ?float
    {
        $actual = KpiActual::query()
            ->withoutGlobalScopes()
            ->where('kpi_definition_id', $kpi->id)
            ->where('mission_id', $mission->id)
            ->where('period_start_date', $periodStart->toDateString())
            ->first();

        return $actual?->actual_value !== null ? (float) $actual->actual_value : null;
    }

    /**
     * CLAUDE.md Section 8: Q1 Jul-Sep, Q2 Oct-Dec, Q3 Jan-Mar, Q4 Apr-Jun —
     * same mapping as ReportService::periodLabelFor()/currentSubmissionPeriod().
     */
    private function quarterStart(int $year, int $quarterNumber): Carbon
    {
        $month = match ($quarterNumber) {
            1 => 7,
            2 => 10,
            3 => 1,
            4 => 4,
        };

        return Carbon::create($year, $month, 1)->startOfDay();
    }

    private function quarterLabel(int $year, int $quarterNumber): string
    {
        return "Q{$quarterNumber} {$year}";
    }

    /**
     * FR-KPI-008: on_track / at_risk / below_target visual status,
     * thresholded on actual-to-target ratio. CLAUDE.md Section 8 KPI
     * Framework Engine Configuration does not define specific thresholds
     * ("pending SDT confirmation" for several adjacent KPI settings); this
     * is a Sprint-0 working default (>=100% on track, >=75% at risk, below
     * that below_target), the same kind of documented placeholder this
     * codebase already uses elsewhere (e.g. Session 25's default template
     * effective_date) — revisit once SDT specifies real thresholds.
     */
    private function performanceStatus(?float $target, ?float $actual): string
    {
        if ($target === null || $target <= 0.0) {
            return 'no_target';
        }

        if ($actual === null) {
            return 'no_data';
        }

        $ratio = $actual / $target;

        return match (true) {
            $ratio >= 1.0 => 'on_track',
            $ratio >= 0.75 => 'at_risk',
            default => 'below_target',
        };
    }

    /**
     * @return Collection<int, Mission>
     */
    private function ministryMissions(string $ministryId): Collection
    {
        return MissionMinistryLink::query()
            ->where('ministry_id', $ministryId)
            ->with('mission')
            ->get()
            ->pluck('mission')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }
}
