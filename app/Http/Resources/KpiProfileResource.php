<?php

namespace App\Http\Resources;

use App\Models\KpiProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-KPI-002: KPI profile shape for GET/POST /kpi-profiles and
 * PATCH /kpi-profiles/{id}/assign.
 *
 * @mixin KpiProfile
 */
class KpiProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ministry_id' => $this->ministry_id,
            'name' => $this->name,
            'kpi_definitions' => $this->whenLoaded('kpiProfileDefinitions', fn () => $this->kpiProfileDefinitions
                ->map(fn ($definition) => [
                    'id' => $definition->kpiDefinition->id,
                    'name' => $definition->kpiDefinition->name,
                ])),
            'assigned_missions' => $this->whenLoaded('kpiProfileMissions', fn () => $this->kpiProfileMissions
                ->map(fn ($assignment) => [
                    'id' => $assignment->mission->id,
                    'name' => $assignment->mission->name,
                ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
