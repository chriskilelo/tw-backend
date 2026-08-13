<?php

namespace Database\Factories;

use App\Enums\SectionType;
use App\Models\Ministry;
use App\Models\ReportTemplateSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTemplateSection>
 */
class ReportTemplateSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'version' => 1,
            'effective_date' => fake()->date(),
            'section_order' => fake()->numberBetween(1, 12),
            'section_title' => fake()->sentence(3),
            'section_type' => fake()->randomElement(SectionType::cases())->value,
            'column_schema' => null,
            'guidance_text' => fake()->optional()->sentence(),
        ];
    }
}
