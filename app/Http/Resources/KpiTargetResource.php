<?php

namespace App\Http\Resources;

use App\Models\KpiTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-KPI-004, FR-KPI-005: KPI target shape for POST /kpi-targets.
 *
 * @mixin KpiTarget
 */
class KpiTargetResource extends JsonResource
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
            'performance_cycle_label' => $this->performance_cycle_label,
            'cycle_start_date' => $this->cycle_start_date,
            'target_value' => $this->target_value,
            'set_by' => $this->whenLoaded('setBy', fn () => [
                'id' => $this->setBy->id,
                'full_name' => $this->setBy->full_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
