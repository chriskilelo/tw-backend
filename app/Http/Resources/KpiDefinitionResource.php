<?php

namespace App\Http\Resources;

use App\Models\KpiDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-KPI-001: KPI definition shape for GET/POST/PATCH /kpi-definitions.
 *
 * @mixin KpiDefinition
 */
class KpiDefinitionResource extends JsonResource
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
            'description' => $this->description,
            'unit' => $this->unit,
            'calculation_method' => $this->calculation_method,
            'data_source' => $this->data_source,
            'reporting_frequency' => $this->reporting_frequency,
            'active' => $this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
