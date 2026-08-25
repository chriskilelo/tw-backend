<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Carbon;

/**
 * The 9 report-bearing quarters spanning FY2024-25 (complete), FY2025-26
 * (complete), and FY2026-27 (in progress, Q1 only — see the plan's "Q2"
 * scope note: Oct-Dec 2026 has not happened yet relative to the system's
 * current date, so it carries no periodic_reports row at all).
 *
 * Mirrors KpiService/ReportService's own quarter-labelling convention
 * exactly (Q1=Jul-Sep, Q2=Oct-Dec, Q3=Jan-Mar, Q4=Apr-Jun, label year = the
 * quarter's own start-month calendar year) — get this wrong and every KPI
 * comparison matrix and compliance dashboard will silently misfile data
 * into the wrong cycle.
 */
final class FiscalQuarters
{
    /**
     * Caps "current quarter" (index 8, Q1 2026) activity dates so freshly
     * seeded alerts/inquiries never land after the environment's actual
     * current date — kept as a fixed constant (rather than reading the real
     * now() at seed time) so re-running the seeder days later still
     * produces the same, reviewable dataset.
     */
    public static function currentDateCap(): Carbon
    {
        return Carbon::create(2026, 8, 15)->endOfDay();
    }

    /**
     * @return array<int, array{label: string, fy: string, start: Carbon, end: Carbon, deadline: Carbon}>
     */
    public static function reportQuarters(): array
    {
        return [
            self::quarter('Q1 2024', 'FY2024-25', 2024, 7),
            self::quarter('Q2 2024', 'FY2024-25', 2024, 10),
            self::quarter('Q3 2025', 'FY2024-25', 2025, 1),
            self::quarter('Q4 2025', 'FY2024-25', 2025, 4),
            self::quarter('Q1 2025', 'FY2025-26', 2025, 7),
            self::quarter('Q2 2025', 'FY2025-26', 2025, 10),
            self::quarter('Q3 2026', 'FY2025-26', 2026, 1),
            self::quarter('Q4 2026', 'FY2025-26', 2026, 4),
            self::quarter('Q1 2026', 'FY2026-27', 2026, 7),
        ];
    }

    /**
     * The 5 KPI half-year cycles corresponding to reportQuarters() above
     * (KpiService::aggregateHalf()'s H1={year}=Q1+Q2{year},
     * H2={year}=Q3+Q4{year} convention — NOT the two halves of one fiscal
     * year, see FiscalQuarters class docblock).
     *
     * @return array<int, array{label: string, fy: string, year: int, half: int}>
     */
    public static function kpiCycles(): array
    {
        return [
            ['label' => 'H1 2024', 'fy' => 'FY2024-25', 'year' => 2024, 'half' => 1],
            ['label' => 'H2 2025', 'fy' => 'FY2024-25', 'year' => 2025, 'half' => 2],
            ['label' => 'H1 2025', 'fy' => 'FY2025-26', 'year' => 2025, 'half' => 1],
            ['label' => 'H2 2026', 'fy' => 'FY2025-26', 'year' => 2026, 'half' => 2],
            ['label' => 'H1 2026', 'fy' => 'FY2026-27', 'year' => 2026, 'half' => 1],
        ];
    }

    /**
     * @return array{label: string, start: Carbon}
     */
    public static function quarterStart(int $year, int $quarterNumber): array
    {
        $month = match ($quarterNumber) {
            1 => 7,
            2 => 10,
            3 => 1,
            4 => 4,
        };

        return [
            'label' => "Q{$quarterNumber} {$year}",
            'start' => Carbon::create($year, $month, 1)->startOfDay(),
        ];
    }

    /**
     * @return array{label: string, fy: string, start: Carbon, end: Carbon, deadline: Carbon}
     */
    private static function quarter(string $label, string $fy, int $year, int $startMonth): array
    {
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addMonths(3)->subDay()->endOfDay();
        $deadline = $end->copy()->addDay()->addDays(14)->endOfDay();

        return [
            'label' => $label,
            'fy' => $fy,
            'start' => $start,
            'end' => $end,
            'deadline' => $deadline,
        ];
    }
}
