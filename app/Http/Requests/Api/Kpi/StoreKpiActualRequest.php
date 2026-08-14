<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-KPI-007: manual actual-value entry. kpi_definition_id is resolved by
 * the controller through a ministry-scoped query, so a KPI outside the
 * entering user's own ministry 404s rather than being accepted here
 * (NFR-SEC-006), mirroring StoreKpiTargetRequest's precedent.
 */
class StoreKpiActualRequest extends FormRequest
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
            'kpi_definition_id' => ['required', 'uuid'],
            'mission_id' => ['required', 'uuid', 'exists:missions,id'],
            'period_label' => ['required', 'string', 'max:50'],
            'period_start_date' => ['required', 'date'],
            'actual_value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
