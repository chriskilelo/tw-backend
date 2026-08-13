<?php

namespace Database\Factories;

use App\Enums\InquiryStatus;
use App\Enums\InquirySubType;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inquiry>
 */
class InquiryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'mission_id' => Mission::factory(),
            'logged_by_user_id' => User::factory(),
            'reference_number' => 'INQ-'.fake()->unique()->numerify('######'),
            'category' => fake()->randomElement([
                'Buyer Seeking Supplier',
                'Investor Seeking Partner',
                'General Market Question',
                'Investor Seeking Buyer',
                'Investor Seeking Investment Opportunities',
                'Disputes/Complaints',
            ]),
            'sub_type' => InquirySubType::Standard->value,
            'inquirer_name' => fake()->name(),
            'inquirer_organisation' => fake()->optional()->company(),
            'inquirer_email' => fake()->optional()->safeEmail(),
            'inquirer_phone' => fake()->optional()->phoneNumber(),
            'product_or_sector' => fake()->optional()->word(),
            'description' => fake()->optional()->paragraph(),
            'date_received' => fake()->date(),
            'status' => InquiryStatus::Draft->value,
            'high_value_flag' => false,
            'high_value_justification' => null,
            'resolution_summary' => null,
            'closed_at' => null,
            'linked_inquiry_id' => null,
        ];
    }
}
