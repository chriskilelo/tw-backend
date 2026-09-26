<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;

/**
 * ADR-006 / BR-004: sets (or, with null, clears) the attache a department has
 * posted to a mission. ministry_id is required from a System Administrator
 * and optional (must be its own) from a Ministry Administrator.
 */
class UpdateMissionLinkRequest extends FormRequest
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
            'ministry_id' => ['nullable', 'uuid', 'exists:ministries,id'],
            'active_attache_user_id' => ['present', 'nullable', 'uuid', 'exists:users,id'],
        ];
    }
}
