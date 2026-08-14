<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-KPI-004, FR-KPI-005, BR-019: sets a versioned numeric target for a
 * mission/KPI/performance cycle. kpi_definition_id is resolved by the
 * controller via a ministry-scoped query, so a KPI outside the setter's
 * own ministry 404s rather than being accepted here (NFR-SEC-006).
 */
class StoreKpiTargetRequest extends FormRequest
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
            'performance_cycle_label' => ['required', 'string', 'max:50'],
            'cycle_start_date' => ['required', 'date'],
            'target_value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
