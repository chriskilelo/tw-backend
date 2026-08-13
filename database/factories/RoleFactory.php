<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'layer' => fake()->randomElement(['1', '2', '3', '2/3']),
            'scope' => fake()->randomElement(['platform', 'ministry', 'mission']),
        ];
    }
}
