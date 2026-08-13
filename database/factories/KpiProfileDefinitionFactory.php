<?php

namespace Database\Factories;

use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\KpiProfileDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiProfileDefinition>
 */
class KpiProfileDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kpi_profile_id' => KpiProfile::factory(),
            'kpi_definition_id' => KpiDefinition::factory(),
        ];
    }
}
