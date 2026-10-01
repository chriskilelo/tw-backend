<?php

namespace Database\Factories;

use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\KpiProfileTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiProfileTarget>
 */
class KpiProfileTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kpi_profile_id' => KpiProfile::factory(),
            'kpi_definition_id' => KpiDefinition::factory(),
            'performance_cycle_label' => 'H1 2026',
            'cycle_start_date' => '2026-07-01',
            'target_value' => fake()->numberBetween(1, 20),
            'set_by_user_id' => User::factory(),
        ];
    }
}
