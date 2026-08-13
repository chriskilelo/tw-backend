<?php

namespace Database\Factories;

use App\Models\KpiDefinition;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiTarget>
 */
class KpiTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mission_id' => Mission::factory(),
            'kpi_definition_id' => KpiDefinition::factory(),
            'performance_cycle_label' => 'H'.fake()->numberBetween(1, 2).' '.fake()->year(),
            'cycle_start_date' => fake()->date(),
            'target_value' => fake()->randomFloat(2, 1, 1000),
            'set_by_user_id' => User::factory(),
        ];
    }
}
