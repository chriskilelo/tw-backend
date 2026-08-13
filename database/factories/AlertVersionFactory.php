<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertVersion>
 */
class AlertVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'snapshot' => ['status' => 'new'],
            'edited_by_user_id' => User::factory(),
        ];
    }
}
