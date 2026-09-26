<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\Concerns\PinsDepartmentForMinistryAdministrator;
use App\Http\Requests\Api\FormRequest;

/**
 * FR-KPI-002: creates a reusable, named group of KPI definitions.
 */
class StoreKpiProfileRequest extends FormRequest
{
    use PinsDepartmentForMinistryAdministrator;

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
            'ministry_id' => $this->departmentRules(),
            'name' => ['required', 'string', 'max:255'],
            'kpi_definition_ids' => ['sometimes', 'array'],
            'kpi_definition_ids.*' => ['uuid', 'exists:kpi_definitions,id'],
        ];
    }
}
