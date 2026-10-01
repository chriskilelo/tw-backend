<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KpiDefinitionSeeder extends Seeder
{
    /**
     * The 11 SDT KPIs (CLAUDE.md Section 8, KPI Framework Engine
     * Configuration). reporting_frequency is 'quarterly' for every
     * definition: CLAUDE.md states the authoritative data source is
     * quarterly reports, auto-aggregated to the half-yearly performance
     * cycle used for target-setting (kpi_targets), not the definition
     * itself. A plain-count source is an App\Services\KpiDataSources key,
     * so it is calculated live (FR-KPI-006); the three Section 2 KPIs keep
     * a description, since narrative report text cannot be counted.
     *
     * @var array<int, array{name: string, method: string, source: ?string}>
     */
    protected array $kpis = [
        ['name' => 'Number of quarterly reports submitted', 'method' => 'auto', 'source' => 'reports.count_submitted'],
        ['name' => 'Number of market intelligence survey reports submitted', 'method' => 'auto', 'source' => 'alerts.count_submitted'],
        ['name' => 'Number of inquiries resolved', 'method' => 'auto', 'source' => 'inquiries.count_closed'],
        ['name' => 'Number of agreements signed', 'method' => 'manual', 'source' => null],
        ['name' => 'Number of trade forums participated in', 'method' => 'auto', 'source' => 'Periodic Report Engine (Section 2 data)'],
        ['name' => 'Increased exports of Kenyan products', 'method' => 'manual', 'source' => null],
        ['name' => 'Number of trade shows and exhibitions attended', 'method' => 'auto', 'source' => 'Periodic Report Engine (Section 2 data)'],
        ['name' => 'Number of dispute resolutions resolved', 'method' => 'auto', 'source' => 'inquiries.count_disputes_closed'],
        ['name' => 'Number of Kenyan delegations hosted', 'method' => 'auto', 'source' => 'Periodic Report Engine (Section 2 data)'],
        ['name' => 'Number of trade briefs submitted', 'method' => 'manual', 'source' => null],
        ['name' => 'Number of engagement strategies submitted', 'method' => 'manual', 'source' => null],
    ];

    /**
     * Seed the SDT KPI library.
     */
    public function run(): void
    {
        $ministryId = DB::table('ministries')
            ->where('name', 'State Department for Trade')
            ->value('id');

        if (! $ministryId) {
            throw new \RuntimeException('SDT ministry not found; run MinistrySeeder first.');
        }

        foreach ($this->kpis as $kpi) {
            $exists = DB::table('kpi_definitions')
                ->where('ministry_id', $ministryId)
                ->where('name', $kpi['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('kpi_definitions')->insert([
                'ministry_id' => $ministryId,
                'name' => $kpi['name'],
                'description' => null,
                'unit' => null,
                'calculation_method' => $kpi['method'],
                'data_source' => $kpi['source'],
                'reporting_frequency' => 'quarterly',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
