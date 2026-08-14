<?php

namespace Database\Factories;

use App\Models\KpiProfile;
use App\Models\KpiProfileMission;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiProfileMission>
 */
class KpiProfileMissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kpi_profile_id' => KpiProfile::factory(),
            'mission_id' => Mission::factory(),
            'assigned_by_user_id' => User::factory(),
        ];
    }
}
