<?php

namespace App\Http\Requests\Api;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'language_preference' => ['sometimes', 'string', 'in:en,sw'],
            'email_notification_preferences' => ['sometimes', 'nullable', 'array'],
            'email_notification_preferences.*' => ['boolean'],
        ];
    }
}
