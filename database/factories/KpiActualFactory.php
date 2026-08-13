<?php

namespace Database\Factories;

use App\Enums\KpiCalculationType;
use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiActual>
 */
class KpiActualFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mission_id' => Mission::factory(),
            'kpi_definition_id' => KpiDefinition::factory(),
            'period_label' => 'Q'.fake()->numberBetween(1, 4).' '.fake()->year(),
            'period_start_date' => fake()->date(),
            'actual_value' => fake()->randomFloat(2, 1, 1000),
            'entered_by_user_id' => User::factory(),
            'calculation_type' => KpiCalculationType::Manual->value,
        ];
    }
}
