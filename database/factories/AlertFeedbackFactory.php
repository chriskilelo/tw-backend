<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertFeedback>
 */
class AlertFeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'posted_by_user_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
