<?php

namespace Database\Factories;

use App\Enums\PeriodicReportStatus;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\PeriodicReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodicReport>
 */
class PeriodicReportFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'ministry_id' => Ministry::factory(),
            'mission_id' => Mission::factory(),
            'authored_by_user_id' => User::factory(),
            'reporting_period_label' => 'Q'.fake()->numberBetween(1, 4).' '.fake()->year(),
            'period_start_date' => $start,
            'period_end_date' => (clone $start)->modify('+3 months'),
            'template_version' => 1,
            'status' => PeriodicReportStatus::Draft->value,
            'submitted_at' => null,
            'is_late' => false,
        ];
    }
}
