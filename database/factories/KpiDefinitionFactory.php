<?php

namespace Database\Factories;

use App\Enums\KpiCalculationType;
use App\Models\KpiDefinition;
use App\Models\Ministry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiDefinition>
 */
class KpiDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'unit' => fake()->randomElement(['count', 'percentage', null]),
            'calculation_method' => fake()->randomElement(KpiCalculationType::cases())->value,
            'data_source' => fake()->optional()->word(),
            'reporting_frequency' => fake()->randomElement(['quarterly', 'half_yearly']),
            'active' => true,
        ];
    }
}
