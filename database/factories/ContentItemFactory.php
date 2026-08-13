<?php

namespace Database\Factories;

use App\Enums\ClassificationLevel;
use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\Ministry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ministry_id' => Ministry::factory(),
            'created_by_user_id' => User::factory(),
            'title' => fake()->sentence(5),
            'category' => fake()->optional()->word(),
            'classification_level' => ClassificationLevel::InternalUseOnly->value,
            'body' => fake()->optional()->paragraphs(3, true),
            'status' => ContentStatus::Draft->value,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'rejection_comment' => null,
            'publication_ready' => false,
            'public_version_of_content_item_id' => null,
        ];
    }
}
