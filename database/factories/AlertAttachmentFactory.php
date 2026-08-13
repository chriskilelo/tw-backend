<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertAttachment>
 */
class AlertAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'file_path' => 'alerts/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'file_size_bytes' => fake()->numberBetween(1024, 5_000_000),
            'mime_type' => 'application/pdf',
            'uploaded_by_user_id' => User::factory(),
        ];
    }
}
