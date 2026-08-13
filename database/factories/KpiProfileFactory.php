<?php

namespace Database\Factories;

use App\Models\KpiProfile;
use App\Models\Ministry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiProfile>
 */
class KpiProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'name' => fake()->randomElement(['High-Volume Mission Profile', 'Standard Mission Profile']),
        ];
    }
}
