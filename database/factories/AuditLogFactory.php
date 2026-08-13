<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['alert.created', 'inquiry.closed', 'directive.issued', 'user.login.failed']),
            'affected_entity_type' => fake()->randomElement(['alert', 'inquiry', 'directive']),
            'affected_entity_id' => (string) Str::uuid(),
            'changes' => ['before' => [], 'after' => []],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
