<?php

namespace App\Http\Requests\Api\Alerts;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-ALERT-007, FR-SDT-002: the primary recipient delegates an alert to one
 * or more HQ users, with an optional note.
 */
class DelegateAlertRequest extends FormRequest
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
            'delegate_user_ids' => ['required', 'array', 'min:1'],
            'delegate_user_ids.*' => ['string', 'uuid', 'exists:users,id'],
            'note' => ['nullable', 'string'],
        ];
    }
}
