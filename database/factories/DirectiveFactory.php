<?php

namespace Database\Factories;

use App\Enums\DirectiveStatus;
use App\Models\Directive;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Directive>
 */
class DirectiveFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'mission_id' => Mission::factory(),
            'target_user_id' => User::factory(),
            'issued_by_user_id' => User::factory(),
            'type_category' => fake()->optional()->word(),
            'description' => fake()->paragraph(),
            'target_completion_date' => fake()->optional()->date(),
            'status' => DirectiveStatus::Draft->value,
            'completion_summary' => null,
            'last_progress_update_at' => null,
        ];
    }
}
