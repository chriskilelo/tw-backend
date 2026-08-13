<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits mission profile fields and, optionally, establishes the attache
 * link for a ministry (mission_ministry_links.active_attache_user_id).
 * ministry_id and active_attache_user_id must be supplied together — see
 * Admin\MissionController::update() for BR-004 enforcement.
 */
class UpdateMissionRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:150'],
            'city' => ['sometimes', 'string', 'max:100'],
            'host_country' => ['sometimes', 'string', 'max:100'],
            'time_zone' => ['sometimes', 'string', 'max:50'],
            'ministry_id' => ['sometimes', 'uuid', Rule::exists('ministries', 'id')],
            'active_attache_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
        ];
    }
}
