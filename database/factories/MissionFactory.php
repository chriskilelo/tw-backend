<?php

namespace Database\Factories;

use App\Models\Mission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mission>
 */
class MissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'city' => fake()->city(),
            'host_country' => fake()->country(),
            'time_zone' => fake()->timezone(),
            'active' => true,
        ];
    }
}
