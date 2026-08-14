<?php

namespace App\Http\Resources;

use App\Models\KpiActual;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-KPI-006, FR-KPI-007: KPI actual shape for GET/POST /kpi-actuals.
 *
 * @mixin KpiActual
 */
class KpiActualResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'kpi_definition' => $this->whenLoaded('kpiDefinition', fn () => [
                'id' => $this->kpiDefinition->id,
                'name' => $this->kpiDefinition->name,
            ]),
            'period_label' => $this->period_label,
            'period_start_date' => $this->period_start_date,
            'actual_value' => $this->actual_value,
            'calculation_type' => $this->calculation_type,
            'entered_by' => $this->whenLoaded('enteredBy', fn () => $this->enteredBy ? [
                'id' => $this->enteredBy->id,
                'full_name' => $this->enteredBy->full_name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
