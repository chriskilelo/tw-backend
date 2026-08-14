<?php

namespace App\Http\Requests\Api\Directives;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-DIR-002, FR-DIR-003, BR-018: mission_id and target_user_id become
 * immutable the moment the directive is issued — captured only here, at
 * creation, never accepted again by UpdateDirectiveStatusRequest.
 */
class StoreDirectiveRequest extends FormRequest
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
            'mission_id' => ['required', 'uuid', 'exists:missions,id'],
            'target_user_id' => ['required', 'uuid', 'exists:users,id'],
            'type_category' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'target_completion_date' => ['nullable', 'date'],
        ];
    }
}
