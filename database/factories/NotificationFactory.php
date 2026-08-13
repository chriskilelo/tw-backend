<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipient_user_id' => User::factory(),
            'trigger_type' => fake()->randomElement(['alert.routed', 'directive.issued', 'report.deadline_reminder']),
            'message' => fake()->sentence(),
            'link' => null,
            'read_at' => null,
        ];
    }
}
