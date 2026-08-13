<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-004: designates $target as Acting PS. No ministry_id field —
 * App\Services\SdtService::activateActingPs() derives the ministry from
 * the target user and validates the activator's own membership in it.
 */
class ActivateActingPsRequest extends FormRequest
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
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ];
    }
}
