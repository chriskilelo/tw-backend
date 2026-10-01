<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;
use App\Support\KpiPeriod;
use Illuminate\Validation\Validator;

/**
 * FR-KPI-003, FR-KPI-004, FR-KPI-005, BR-019: one versioned target for a
 * half-yearly performance cycle ("H1 2026"), either a mission override
 * (mission_id) or a KPI Profile default (kpi_profile_id). The cycle's start
 * date is derived from the label; a client may send it, but only if it
 * matches. The KPI, mission and profile are resolved against the setter's
 * own department by the controller and KpiService, so another
 * department's ids are rejected rather than accepted here (NFR-SEC-006).
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
            'mission_id' => ['required_without:kpi_profile_id', 'prohibits:kpi_profile_id', 'nullable', 'uuid'],
            'kpi_profile_id' => ['nullable', 'uuid'],
            'performance_cycle_label' => ['required', 'string', 'max:50'],
            'cycle_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'target_value' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $label = (string) $this->input('performance_cycle_label');
                if (! KpiPeriod::isHalfLabel($label)) {
                    $validator->errors()->add('performance_cycle_label', 'Targets are set for a half-yearly performance cycle, such as H1 2026.');

                    return;
                }

                $start = KpiPeriod::fromLabel($label)->start()->toDateString();
                if ($this->filled('cycle_start_date') && $this->input('cycle_start_date') !== $start) {
                    $validator->errors()->add('cycle_start_date', "{$label} starts on {$start}.");
                }
            },
        ];
    }

    public function cycle(): KpiPeriod
    {
        return KpiPeriod::fromLabel((string) $this->validated('performance_cycle_label'));
    }
}
