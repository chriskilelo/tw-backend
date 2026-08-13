<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralEntry>
 */
class ReferralEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inquiry_id' => Inquiry::factory(),
            'referral_organisation_id' => ReferralOrganisation::factory(),
            'contact_person' => fake()->optional()->name(),
            'referral_date' => fake()->date(),
            'referral_method' => fake()->optional()->randomElement(['email', 'phone', 'letter']),
            'reference_number' => fake()->optional()->bothify('REF-####'),
            'remarks' => fake()->optional()->sentence(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
