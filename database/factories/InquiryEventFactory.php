<?php

namespace Database\Factories;

use App\Enums\InquiryEventType;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InquiryEvent>
 */
class InquiryEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inquiry_id' => Inquiry::factory(),
            'event_type' => fake()->randomElement(InquiryEventType::cases())->value,
            'note' => fake()->optional()->sentence(),
            'logged_by_user_id' => User::factory(),
        ];
    }
}
