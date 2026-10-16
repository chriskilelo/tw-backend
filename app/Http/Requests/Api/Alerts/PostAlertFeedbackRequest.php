<?php

namespace App\Http\Requests\Api\Alerts;

use App\Http\Requests\Api\FormRequest;
use App\Models\Alert;

/**
 * FR-ALERT-010: post a timestamped feedback entry on an alert.
 * AlertPolicy::postFeedback() is checked before validation, so a user who
 * may not post on this alert gets 403 whatever it sends. The 2000-character
 * limit is the one the alert detail page and the HQ workspace enforce.
 */
class PostAlertFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alert = $this->route('alert');

        return $alert instanceof Alert && ($this->user()?->can('postFeedback', $alert) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:2000'],
        ];
    }
}
