<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-AUTH-022: a Ministry Administrator requests a new account as its department's Principal Secretary. The account is created only when a System Administrator approves (BR-027).
 */
class StorePsAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ];
    }
}
