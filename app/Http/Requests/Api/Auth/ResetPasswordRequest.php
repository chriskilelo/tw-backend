<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // FR-AUTH-008: minimum 8 characters, at least one uppercase,
            // one lowercase, and one numeric character.
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }
}
