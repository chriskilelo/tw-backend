<?php

namespace Database\Factories;

use App\Models\ReferralAttachment;
use App\Models\ReferralEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralAttachment>
 */
class ReferralAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'referral_entry_id' => ReferralEntry::factory(),
            'file_path' => 'referrals/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'file_size_bytes' => fake()->numberBetween(1024, 5_000_000),
            'mime_type' => 'application/pdf',
            'uploaded_by_user_id' => User::factory(),
        ];
    }
}
