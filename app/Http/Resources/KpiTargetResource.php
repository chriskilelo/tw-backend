<?php

namespace App\Http\Resources;

use App\Models\KpiProfileTarget;
use App\Models\KpiTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-KPI-003 to 005: one target version from POST /kpi-targets — a mission
 * override (KpiTarget) or a KPI Profile default (KpiProfileTarget).
 *
 * @mixin KpiTarget|KpiProfileTarget
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
            'scope' => $this->resource instanceof KpiProfileTarget ? 'profile' : 'mission',
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'kpi_profile' => $this->whenLoaded('kpiProfile', fn () => [
                'id' => $this->kpiProfile->id,
                'name' => $this->kpiProfile->name,
            ]),
            'kpi_definition' => $this->whenLoaded('kpiDefinition', fn () => [
                'id' => $this->kpiDefinition->id,
                'name' => $this->kpiDefinition->name,
            ]),
            'performance_cycle_label' => $this->performance_cycle_label,
            'cycle_start_date' => $this->cycle_start_date?->toDateString(),
            'target_value' => $this->target_value,
            'note' => $this->note,
            'set_by' => $this->whenLoaded('setBy', fn () => [
                'id' => $this->setBy->id,
                'full_name' => $this->setBy->full_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
