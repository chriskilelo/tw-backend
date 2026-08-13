<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SlaConfigurationSeeder extends Seeder
{
    /**
     * The AIE budget code registry (CLAUDE.md Section 8, AIE Allocations
     * Table Schema). This is not a standalone SLA table; the per-row
     * fields (Quarter Allocation, Deficit/Surplus, Remarks) are already
     * captured in report_template_sections.column_schema for the "AIE
     * Allocations Analysis" section (see ReportTemplateSectionSeeder).
     * What belongs here is the fixed budget code lookup list itself,
     * seeded as master_data_entries so it can be reused (e.g. row
     * pre-population, FY-versioned overrides per FR-RPT-008) without a
     * schema change.
     *
     * @var array<int, string>
     */
    protected array $budgetCodes = [
        '2110300 — Personal Allowances-FSA',
        '2210100 — Utilities: Electricity and Water',
        '2210200 — Communication Supplies and Services',
        '2210300 — Domestic Travel and Subsistence',
        '2210400 — Foreign Travel and Subsistence',
        '2210500 — Printing, Advertising and Information Supplies and Services',
        '2210505 — Trade Shows and Exhibitions',
        '2210600 — Payment of Rents',
        '2210800 — Hospitality Supplies and Services',
        '2210900 — Insurance Costs',
        '2211100 — Office and General Supplies and Services',
        '2211200 — Fuel Oil and Lubricants',
        '2211300 — Other Operating Expenses',
        '2220200 — Routine Maintenance: Other Assets',
        '2230100 — Exchange Rate Losses',
        '2640100 — Scholarships and Other Educational Benefits',
        '3110900 — Purchase of Household Furniture and Institutional Equipment',
        'N/A — Bank Account Balance',
        'N/A — TOTAL (auto-calculated row)',
    ];

    protected const CATEGORY = 'aie_budget_code';

    /**
     * Seed the AIE budget code lookup list into master_data_entries.
     */
    public function run(): void
    {
        $ministryId = DB::table('ministries')
            ->where('name', 'State Department for Trade')
            ->value('id');

        if (! $ministryId) {
            throw new \RuntimeException('SDT ministry not found; run MinistrySeeder first.');
        }

        foreach ($this->budgetCodes as $index => $value) {
            $exists = DB::table('master_data_entries')
                ->where('ministry_id', $ministryId)
                ->where('category', self::CATEGORY)
                ->where('value', $value)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('master_data_entries')->insert([
                'ministry_id' => $ministryId,
                'category' => self::CATEGORY,
                'value' => $value,
                'display_order' => $index + 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
