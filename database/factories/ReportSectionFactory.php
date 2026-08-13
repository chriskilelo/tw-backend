<?php

namespace Database\Factories;

use App\Models\PeriodicReport;
use App\Models\ReportSection;
use App\Models\ReportTemplateSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportSection>
 */
class ReportSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'periodic_report_id' => PeriodicReport::factory(),
            'report_template_section_id' => ReportTemplateSection::factory(),
            'content' => fake()->optional()->paragraphs(2, true),
        ];
    }
}
