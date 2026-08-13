<?php

namespace Database\Factories;

use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'mission_id' => Mission::factory(),
            'submitted_by_user_id' => User::factory(),
            'reference_number' => 'ALT-'.fake()->unique()->numerify('######'),
            'country' => fake()->country(),
            'sector' => fake()->optional()->word(),
            'product_category' => fake()->optional()->word(),
            'product_description' => fake()->optional()->sentence(),
            'intelligence_type' => fake()->randomElement(['opportunities', 'trade_barriers']),
            'intelligence_source' => fake()->optional()->company(),
            'urgency' => fake()->optional()->randomElement(['low', 'medium', 'high']),
            'confidence_rating' => fake()->optional()->randomElement(['low', 'medium', 'high']),
            'tags' => null,
            'status' => AlertStatus::New->value,
            'assigned_to_user_id' => null,
        ];
    }
}
