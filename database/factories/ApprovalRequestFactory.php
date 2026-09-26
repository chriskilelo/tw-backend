<?php

namespace Database\Factories;

use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalRequestType;
use App\Models\ApprovalRequest;
use App\Models\Ministry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'type' => ApprovalRequestType::PsAppointment,
            'status' => ApprovalRequestStatus::Pending,
            'requested_by_user_id' => User::factory(),
            'subject_user_id' => null,
            'payload' => [
                'full_name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
            ],
        ];
    }
}
