<?php

namespace Database\Factories;

use App\Models\MasterDataEntry;
use App\Models\Ministry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MasterDataEntry>
 */
class MasterDataEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'category' => fake()->randomElement(['inquiry_category', 'alert_intelligence_type', 'directive_type', 'content_category']),
            'value' => fake()->words(3, true),
            'display_order' => fake()->numberBetween(0, 20),
            'active' => true,
        ];
    }
}
