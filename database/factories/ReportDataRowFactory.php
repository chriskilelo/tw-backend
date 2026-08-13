<?php

namespace Database\Factories;

use App\Models\ReportDataRow;
use App\Models\ReportSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportDataRow>
 */
class ReportDataRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'report_section_id' => ReportSection::factory(),
            'row_order' => fake()->numberBetween(1, 20),
            'row_data' => ['item_description' => fake()->words(3, true)],
        ];
    }
}
