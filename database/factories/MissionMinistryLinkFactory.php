<?php

namespace Database\Factories;

use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissionMinistryLink>
 */
class MissionMinistryLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mission_id' => Mission::factory(),
            'ministry_id' => Ministry::factory(),
            'active_attache_user_id' => null,
        ];
    }
}
