<?php

namespace Database\Factories;

use App\Models\Ministry;
use App\Models\ReferralOrganisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralOrganisation>
 */
class ReferralOrganisationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'name' => fake()->unique()->company(),
            'active' => true,
        ];
    }
}
