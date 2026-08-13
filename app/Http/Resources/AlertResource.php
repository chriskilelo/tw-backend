<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List/summary shape for GET /alerts (FR-ALERT-006, FR-ALERT-014).
 *
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'country' => $this->country,
            'sector' => $this->sector,
            'product_category' => $this->product_category,
            'intelligence_type' => $this->intelligence_type,
            'urgency' => $this->urgency,
            'status' => $this->status,
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => [
                'id' => $this->submittedBy->id,
                'full_name' => $this->submittedBy->full_name,
            ]),
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'created_at' => $this->created_at,
        ];
    }
}
