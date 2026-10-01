<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-KPI-006: KpiDefinitionSeeder stored descriptive prose in
 * kpi_definitions.data_source, which no calculator recognises, so none of
 * SDT's auto-calculated KPIs were ever computed from live data (the
 * Session 33 known gap). Rewrites the four whose source is a plain count
 * to App\Services\KpiDataSources keys. The three "Section 2 data" KPIs keep
 * their prose: Section 2 is narrative, so their values are still recorded.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const array KEYS = [
        'Periodic Report Engine' => 'reports.count_submitted',
        'Intelligence Alert Engine' => 'alerts.count_submitted',
        'Inquiry and Case Tracker Engine (status = Closed)' => 'inquiries.count_closed',
        'Inquiry and Case Tracker Engine (category = Disputes/Complaints, status = Closed)' => 'inquiries.count_disputes_closed',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $prose => $key) {
            DB::table('kpi_definitions')
                ->where('calculation_method', 'auto')
                ->where('data_source', $prose)
                ->update(['data_source' => $key, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $prose => $key) {
            DB::table('kpi_definitions')
                ->where('calculation_method', 'auto')
                ->where('data_source', $key)
                ->update(['data_source' => $prose, 'updated_at' => now()]);
        }
    }
};
