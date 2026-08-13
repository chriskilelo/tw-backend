<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ContentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentVersion>
 */
class ContentVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'version_number' => 1,
            'snapshot' => ['title' => fake()->sentence(4)],
            'created_by_user_id' => User::factory(),
        ];
    }
}
