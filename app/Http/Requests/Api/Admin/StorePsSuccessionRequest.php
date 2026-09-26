<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-AUTH-023: a Ministry Administrator requests that the outgoing Principal Secretary be replaced in one step — either by an existing department account (incoming_user_id) or by a new account (incoming_full_name + incoming_email).
 */
class StorePsSuccessionRequest extends FormRequest
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
            'outgoing_user_id' => ['required', 'uuid', 'exists:users,id'],
            'incoming_user_id' => ['nullable', 'uuid', 'exists:users,id', 'required_without:incoming_email', 'prohibits:incoming_email,incoming_full_name'],
            'incoming_full_name' => ['nullable', 'string', 'max:255', 'required_with:incoming_email'],
            'incoming_email' => ['nullable', 'email', 'max:255', 'required_without:incoming_user_id', Rule::unique('users', 'email')],
        ];
    }
}
