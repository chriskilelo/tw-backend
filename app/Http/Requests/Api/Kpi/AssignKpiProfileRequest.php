<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-KPI-002 (mission-grouping): assigns a KPI Profile to a set of
 * missions. mission_ids is the complete desired set, not an incremental
 * add — App\Services\KpiService::assignProfileToMissions() replaces the
 * profile's existing assignment with exactly this list.
 */
class AssignKpiProfileRequest extends FormRequest
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
            'mission_ids' => ['required', 'array'],
            'mission_ids.*' => ['uuid', 'exists:missions,id'],
        ];
    }
}
