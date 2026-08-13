<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Mission;
use App\Models\PeriodicReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CLAUDE.md Section 11 / FR-HOM-001 to 003, FR-MFA-001 to 003: read-only
 * aggregation across the four Layer 2 submission types (alerts, inquiries,
 * directives, periodic reports) that feed the mission-governance
 * dashboards. Session 13 builds only the GET paths these FRs describe;
 * nothing here ever mutates a record.
 *
 * Every query here explicitly calls withoutGlobalScopes() rather than
 * relying on the ministry.scope middleware's bypass-role behaviour
 * (App\Http\Middleware\MinistryScope, Session 5): the whole point of these
 * two engines is cross-ministry (and, for MFA, cross-mission) visibility,
 * so scoping is controlled entirely by this service's own $mission
 * parameter and the $ministryId filter, not left implicit in the caller's
 * role.
 *
 * Like ReferralService::getReferralSummary() (Session 12), aggregation is
 * done in PHP over eager-loaded collections rather than SQL GROUP BY/UNION
 * across four separate tables — fine at current data volumes, revisit with
 * real SQL if submission volume grows.
 */
class GovernanceService
{
    private const array FEED_TYPES = ['alert', 'inquiry', 'directive', 'periodic_report'];

    /**
     * FR-HOM-001: chronological feed of submissions from every ministry
     * with an attache at the given mission, optionally narrowed to one
     * ministry (FR-HOM-001 AC3).
     */
    public function missionActivityFeed(Mission $mission, ?string $ministryId = null): Collection
    {
        return $this->itemsForMission($mission)
            ->when($ministryId !== null, fn (Collection $items) => $items->where('ministry_id', $ministryId))
            ->sortByDesc('date')
            ->values();
    }

    /**
     * FR-HOM-002: counts of submissions by type and status for the current
     * and prior reporting quarter.
     *
     * @return array<string, mixed>
     */
    public function missionActivitySummary(Mission $mission): array
    {
        return $this->summariseAcrossQuarters($this->itemsForMission($mission));
    }

    /**
     * FR-MFA-002: a single mission's summary, equivalent to FR-HOM-002.
     *
     * @return array<string, mixed>
     */
    public function missionDrillDown(Mission $mission): array
    {
        return $this->missionActivitySummary($mission);
    }

    /**
     * FR-MFA-001: aggregate counts of submissions by mission, by ministry,
     * and by period, across every mission and ministry.
     *
     * @return array<string, mixed>
     */
    public function mfaAwarenessSummary(): array
    {
        $items = $this->allItems();

        return [
            'total' => $items->count(),
            'by_type' => $items->groupBy('type')->map->count(),
            'by_mission' => $items->groupBy('mission_name')->map->count(),
            'by_ministry' => $items->groupBy('ministry_name')->map->count(),
            'by_period' => $items->groupBy(fn (array $item) => $item['date']->format('Y-m'))->map->count(),
        ];
    }

    /**
     * FR-MFA-003: cross-mission, cross-ministry comparison for the
     * national overview. Fetches every record once and groups in memory
     * rather than re-querying per mission.
     *
     * @return array<string, mixed>
     */
    public function nationalOverview(): array
    {
        $missions = Mission::query()->where('active', true)->orderBy('name')->get();
        $itemsByMission = $this->allItems()->groupBy('mission_id');

        return [
            'missions' => $missions->map(fn (Mission $mission) => [
                'mission_id' => $mission->id,
                'mission_name' => $mission->name,
                ...$this->summariseAcrossQuarters($itemsByMission->get($mission->id, collect())),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summariseAcrossQuarters(Collection $items): array
    {
        [$currentStart, $currentEnd] = $this->quarterBounds(0);
        [$priorStart, $priorEnd] = $this->quarterBounds(-1);

        return [
            'current_period' => $this->summarisePeriod($items, $currentStart, $currentEnd),
            'prior_period' => $this->summarisePeriod($items, $priorStart, $priorEnd),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarisePeriod(Collection $items, Carbon $start, Carbon $end): array
    {
        $periodItems = $items->filter(fn (array $item) => $item['date']->betweenIncluded($start, $end));

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'total' => $periodItems->count(),
            'by_type' => $periodItems->groupBy('type')->map->count(),
            'by_status' => $periodItems->groupBy(fn (array $item) => "{$item['type']}:{$item['status']}")->map->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function itemsForMission(Mission $mission): Collection
    {
        return collect(self::FEED_TYPES)->flatMap(
            fn (string $type) => $this->baseQuery($type)->where('mission_id', $mission->id)->get()
                ->map(fn (Model $record) => $this->normalize($type, $record))
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function allItems(): Collection
    {
        return collect(self::FEED_TYPES)->flatMap(
            fn (string $type) => $this->baseQuery($type)->get()->map(fn (Model $record) => $this->normalize($type, $record))
        );
    }

    private function baseQuery(string $type): Builder
    {
        return match ($type) {
            'alert' => Alert::query()->withoutGlobalScopes()->with(['ministry', 'mission', 'submittedBy']),
            'inquiry' => Inquiry::query()->withoutGlobalScopes()->with(['ministry', 'mission', 'loggedBy']),
            'directive' => Directive::query()->withoutGlobalScopes()->with(['ministry', 'mission', 'issuedBy']),
            'periodic_report' => PeriodicReport::query()->withoutGlobalScopes()->with(['ministry', 'mission', 'authoredBy']),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(string $type, Model $record): array
    {
        return [
            'type' => $type,
            'id' => $record->id,
            'reference' => match ($type) {
                'alert', 'inquiry' => $record->reference_number,
                'periodic_report' => $record->reporting_period_label,
                'directive' => $record->type_category ?? 'Directive',
            },
            'mission_id' => $record->mission_id,
            'mission_name' => $record->mission?->name,
            'ministry_id' => $record->ministry_id,
            'ministry_name' => $record->ministry?->name,
            'submitting_officer' => match ($type) {
                'alert' => $record->submittedBy?->full_name,
                'inquiry' => $record->loggedBy?->full_name,
                'directive' => $record->issuedBy?->full_name,
                'periodic_report' => $record->authoredBy?->full_name,
            },
            'status' => $record->status?->value,
            'summary' => match ($type) {
                'alert' => trim("{$record->country} — {$record->intelligence_type}"),
                'inquiry' => trim("{$record->category} — {$record->inquirer_name}"),
                'directive' => str($record->description)->limit(120)->toString(),
                'periodic_report' => "Report for {$record->reporting_period_label}",
            },
            'date' => $record->created_at,
        ];
    }

    /**
     * CLAUDE.md Section 8 reporting calendar: Q1 Jul-Sep, Q2 Oct-Dec,
     * Q3 Jan-Mar, Q4 Apr-Jun (Kenyan financial year, starts 1 July).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function quarterBounds(int $quartersAgo): array
    {
        $now = Carbon::now();
        $fiscalYearStartYear = $now->month >= 7 ? $now->year : $now->year - 1;
        $fiscalYearStart = Carbon::create($fiscalYearStartYear, 7, 1)->startOfDay();

        $currentQuarterIndex = intdiv($fiscalYearStart->diffInMonths($now), 3);
        $targetQuarterStart = $fiscalYearStart->copy()->addMonths(($currentQuarterIndex + $quartersAgo) * 3);

        return [$targetQuarterStart->copy(), $targetQuarterStart->copy()->addMonths(3)->subSecond()];
    }
}
