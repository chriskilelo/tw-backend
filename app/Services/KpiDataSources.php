<?php

namespace App\Services;

use App\Enums\InquiryStatus;
use App\Enums\PeriodicReportStatus;
use App\Models\Alert;
use App\Models\Inquiry;
use App\Models\KpiDefinition;
use App\Models\PeriodicReport;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * FR-KPI-006 and FR-KPI-012: the Layer 2 data an auto-calculated KPI can be
 * computed from, counted per mission and fiscal quarter straight from the
 * engine tables. A KPI is calculated live only when its calculation_method
 * is 'auto' and its data_source is one of these keys; any other KPI
 * (manual, or auto with a source that is not a simple count, such as the
 * Section 2 narrative KPIs) takes its values from kpi_actuals.
 *
 * Quarters are fiscal (Q1 Jul-Sep ... Q4 Apr-Jun), which fall on calendar
 * quarter boundaries, so Postgres' date_trunc('quarter') buckets rows by
 * the same quarter start KpiPeriod uses.
 */
class KpiDataSources
{
    public const string ALERTS_SUBMITTED = 'alerts.count_submitted';

    public const string INQUIRIES_CLOSED = 'inquiries.count_closed';

    public const string DISPUTES_CLOSED = 'inquiries.count_disputes_closed';

    public const string REPORTS_SUBMITTED = 'reports.count_submitted';

    public const string REPORTS_SUBMITTED_ON_TIME = 'reports.count_submitted_on_time';

    /**
     * @var array<int, string>
     */
    public const array KEYS = [
        self::ALERTS_SUBMITTED,
        self::INQUIRIES_CLOSED,
        self::DISPUTES_CLOSED,
        self::REPORTS_SUBMITTED,
        self::REPORTS_SUBMITTED_ON_TIME,
    ];

    public static function isKey(?string $source): bool
    {
        return $source !== null && in_array($source, self::KEYS, true);
    }

    public static function isLive(KpiDefinition $kpi): bool
    {
        return $kpi->calculation_method === 'auto' && self::isKey($kpi->data_source);
    }

    /**
     * Counts per mission and quarter for one source, over every quarter
     * starting from $from up to and including the one starting $to.
     *
     * @param  array<int, string>  $missionIds
     * @return array<string, array<string, float>> keyed by mission id, then quarter start (Y-m-d)
     */
    public function quarterlyCounts(string $source, string $ministryId, array $missionIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($missionIds === []) {
            return [];
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $rows = match ($source) {
            self::ALERTS_SUBMITTED => $this->bucketByQuarter(
                Alert::query()->withoutGlobalScopes(),
                'created_at',
                $ministryId,
                $missionIds,
                $start,
                $end,
            ),
            self::INQUIRIES_CLOSED => $this->bucketByQuarter(
                Inquiry::query()->withoutGlobalScopes()->where('status', InquiryStatus::Closed->value),
                'closed_at',
                $ministryId,
                $missionIds,
                $start,
                $end,
            ),
            self::DISPUTES_CLOSED => $this->bucketByQuarter(
                Inquiry::query()
                    ->withoutGlobalScopes()
                    ->where('status', InquiryStatus::Closed->value)
                    ->where(fn (Builder $query) => $query->where('sub_type', 'dispute_or_complaint')->orWhere('category', 'Disputes/Complaints')),
                'closed_at',
                $ministryId,
                $missionIds,
                $start,
                $end,
            ),
            self::REPORTS_SUBMITTED => $this->reportsByPeriod($ministryId, $missionIds, $start, $end, onTimeOnly: false),
            self::REPORTS_SUBMITTED_ON_TIME => $this->reportsByPeriod($ministryId, $missionIds, $start, $end, onTimeOnly: true),
            default => [],
        };

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->mission_id][substr((string) $row->quarter_start, 0, 10)] = (float) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  array<int, string>  $missionIds
     * @return iterable<int, object{mission_id: string, quarter_start: string, aggregate: int}>
     */
    private function bucketByQuarter(Builder $query, string $column, string $ministryId, array $missionIds, CarbonInterface $start, CarbonInterface $end): iterable
    {
        return $query
            ->where('ministry_id', $ministryId)
            ->whereIn('mission_id', $missionIds)
            ->whereBetween($column, [$start, $end])
            ->selectRaw("mission_id, date_trunc('quarter', {$column})::date as quarter_start, count(*) as aggregate")
            ->groupBy('mission_id', 'quarter_start')
            ->toBase()
            ->get();
    }

    /**
     * Reports count against the quarter they report on (period_start_date),
     * not the day they were submitted: Q1's report, filed in October, is
     * Q1's KPI. On-time only (FR-KPI-012) drops reports flagged late.
     *
     * @param  array<int, string>  $missionIds
     * @return iterable<int, object{mission_id: string, quarter_start: string, aggregate: int}>
     */
    private function reportsByPeriod(string $ministryId, array $missionIds, CarbonInterface $start, CarbonInterface $end, bool $onTimeOnly): iterable
    {
        return PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->whereIn('mission_id', $missionIds)
            ->where('status', PeriodicReportStatus::Submitted->value)
            ->when($onTimeOnly, fn (Builder $query) => $query->where('is_late', false))
            ->whereBetween('period_start_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('mission_id, period_start_date as quarter_start, count(*) as aggregate')
            ->groupBy('mission_id', 'period_start_date')
            ->toBase()
            ->get();
    }
}
