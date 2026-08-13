<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'permission_key' => fake()->randomElement(['alert', 'inquiry', 'directive', 'report']).'.'.fake()->randomElement(['view', 'create', 'update']),
            'read_only' => false,
        ];
    }
}
