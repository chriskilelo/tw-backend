<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-KPI-001: defines a new KPI. Authorization is enforced in the
 * controller via KpiPolicy::manageDefinitions(), not here.
 */
class StoreKpiDefinitionRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'calculation_method' => ['required', 'string', 'in:auto,manual'],
            'data_source' => ['required_if:calculation_method,auto', 'nullable', 'string', 'max:100'],
            'reporting_frequency' => ['required', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
