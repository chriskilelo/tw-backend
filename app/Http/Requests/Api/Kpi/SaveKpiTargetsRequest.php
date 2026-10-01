<?php

namespace App\Http\Requests\Api\Kpi;

use App\Http\Requests\Api\FormRequest;
use App\Support\KpiPeriod;
use Illuminate\Validation\Validator;

/**
 * FR-KPI-003 to 005: the target planner's save — several targets for one
 * half-yearly cycle at once, each a mission override or a KPI Profile
 * default. KpiService::saveTargets() checks every id against the setter's
 * own department and saves all or nothing.
 */
class SaveKpiTargetsRequest extends FormRequest
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
            'performance_cycle_label' => ['required', 'string', 'max:50'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'targets' => ['required', 'array', 'min:1', 'max:1000'],
            'targets.*.kpi_definition_id' => ['required', 'uuid'],
            'targets.*.mission_id' => ['required_without:targets.*.kpi_profile_id', 'nullable', 'uuid'],
            'targets.*.kpi_profile_id' => ['nullable', 'uuid'],
            'targets.*.target_value' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
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

                if (! KpiPeriod::isHalfLabel((string) $this->input('performance_cycle_label'))) {
                    $validator->errors()->add('performance_cycle_label', 'Targets are set for a half-yearly performance cycle, such as H1 2026.');
                }

                foreach ((array) $this->input('targets') as $index => $target) {
                    if (! empty($target['mission_id']) && ! empty($target['kpi_profile_id'])) {
                        $validator->errors()->add("targets.{$index}", 'A target is either for a mission or for a KPI profile, not both.');
                    }
                }
            },
        ];
    }

    public function cycle(): KpiPeriod
    {
        return KpiPeriod::fromLabel((string) $this->validated('performance_cycle_label'));
    }
}
