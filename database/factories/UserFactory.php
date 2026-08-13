<?php

namespace Database\Factories;

use App\Enums\LanguagePreference;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => Role::factory(),
            'mission_id' => null,
            'ministry_id' => null,
            'status' => UserStatus::Active,
            'failed_login_attempts' => 0,
            'language_preference' => LanguagePreference::English,
            'email_notification_preferences' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::ActivationPending,
        ]);
    }
}
