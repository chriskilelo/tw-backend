<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;
use App\Support\KpiPeriod;
use Illuminate\Validation\Validator;

/**
 * FR-KPI-007: a manually entered actual for one quarter ("Q1 2026"). The
 * quarter's start date is derived from the label; a client may send it,
 * but only if it matches. kpi_definition_id is resolved by the controller
 * through a ministry-scoped query, so a KPI outside the entering user's
 * department 404s (NFR-SEC-006); KpiService::assertActualRecordable()
 * applies the remaining rules.
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
            'period_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'actual_value' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
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

                $label = (string) $this->input('period_label');
                if (! KpiPeriod::isLabel($label) || ! str_starts_with($label, 'Q')) {
                    $validator->errors()->add('period_label', 'Actuals are recorded per quarter, such as Q1 2026.');

                    return;
                }

                $start = KpiPeriod::fromLabel($label)->start()->toDateString();
                if ($this->filled('period_start_date') && $this->input('period_start_date') !== $start) {
                    $validator->errors()->add('period_start_date', "{$label} starts on {$start}.");
                }
            },
        ];
    }

    public function quarter(): KpiPeriod
    {
        return KpiPeriod::fromLabel((string) $this->validated('period_label'));
    }
}
