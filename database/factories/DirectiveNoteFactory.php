<?php

namespace Database\Factories;

use App\Models\Directive;
use App\Models\DirectiveNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectiveNote>
 */
class DirectiveNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'directive_id' => Directive::factory(),
            'authored_by_user_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
