<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-RPT-008 (pre-populated rows) and FR-RPT-009 (auto-calculated total
 * row): section-level behaviour for a structured_table section that
 * column_schema (a list of columns) has no place for. Shape, all keys
 * optional:
 *
 *   {
 *     "prepopulate": {"master_data_category": "aie_budget_code",
 *                     "label_columns": ["Budget Code", "Head Description"]},
 *     "total": {"label": "TOTAL", "label_column": "Head Description",
 *               "sum_columns": ["Quarter Allocation", "Deficit/Surplus"],
 *               "exclude_labels": ["Bank Account Balance"]},
 *     "max_rows": 200
 *   }
 *
 * The backfill applies TW-URD-D Section 2.3's AIE Allocations configuration
 * (budget codes pre-populated from the aie_budget_code master data, TOTAL
 * auto-calculated) to existing installations, whose version 1 template was
 * seeded before this column existed. It matches the AIE column schema, not
 * a ministry name, and only fills a null table_config. Existing reports keep
 * their rows; pre-population only runs when a new draft is created.
 */
return new class extends Migration
{
    private const array AIE_COLUMNS = ['Budget Code', 'Head Description', 'Quarter Allocation'];

    public function up(): void
    {
        Schema::table('report_template_sections', function (Blueprint $table) {
            $table->jsonb('table_config')->nullable()->after('column_schema');
        });

        DB::table('report_template_sections')
            ->where('section_type', 'structured_table')
            ->whereNull('table_config')
            ->get(['id', 'column_schema'])
            ->filter(function (object $section): bool {
                $columns = collect(json_decode((string) $section->column_schema, true) ?: [])->pluck('name');

                return collect(self::AIE_COLUMNS)->every(fn (string $name): bool => $columns->contains($name));
            })
            ->each(fn (object $section) => DB::table('report_template_sections')
                ->where('id', $section->id)
                ->update(['table_config' => json_encode([
                    'prepopulate' => [
                        'master_data_category' => 'aie_budget_code',
                        'label_columns' => ['Budget Code', 'Head Description'],
                    ],
                    'total' => [
                        'label' => 'TOTAL',
                        'label_column' => 'Head Description',
                        'sum_columns' => ['Quarter Allocation', 'Deficit/Surplus'],
                        'exclude_labels' => ['Bank Account Balance'],
                    ],
                ])]));
    }

    public function down(): void
    {
        Schema::table('report_template_sections', function (Blueprint $table) {
            $table->dropColumn('table_config');
        });
    }
};
