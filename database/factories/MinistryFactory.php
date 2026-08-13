<?php

namespace Database\Factories;

use App\Models\Ministry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ministry>
 */
class MinistryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Ministry',
            'active' => true,
        ];
    }
}
