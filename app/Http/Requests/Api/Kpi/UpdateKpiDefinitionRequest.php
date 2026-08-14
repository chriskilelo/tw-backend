<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-KPI-001: edits a KPI definition. ministry_id is deliberately not
 * editable, matching UpdateAlertFieldRequest's precedent.
 */
class UpdateKpiDefinitionRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'calculation_method' => ['sometimes', 'string', 'in:auto,manual'],
            'data_source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reporting_frequency' => ['sometimes', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
