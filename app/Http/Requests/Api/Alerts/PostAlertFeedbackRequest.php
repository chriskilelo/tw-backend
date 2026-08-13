<?php

namespace App\Http\Requests\Api\Alerts;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-ALERT-010: post a timestamped feedback entry on an alert.
 */
class PostAlertFeedbackRequest extends FormRequest
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
            'content' => ['required', 'string'],
        ];
    }
}
