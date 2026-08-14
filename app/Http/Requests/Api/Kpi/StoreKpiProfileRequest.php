<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-KPI-002: creates a reusable, named group of KPI definitions.
 */
class StoreKpiProfileRequest extends FormRequest
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
            'ministry_id' => ['required', 'uuid', 'exists:ministries,id'],
            'name' => ['required', 'string', 'max:255'],
            'kpi_definition_ids' => ['sometimes', 'array'],
            'kpi_definition_ids.*' => ['uuid', 'exists:kpi_definitions,id'],
        ];
    }
}
