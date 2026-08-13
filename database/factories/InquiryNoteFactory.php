<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\InquiryNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InquiryNote>
 */
class InquiryNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inquiry_id' => Inquiry::factory(),
            'authored_by_user_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
