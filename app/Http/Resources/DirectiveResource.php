<?php

namespace App\Http\Resources;

use App\Models\Directive;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List/summary shape for GET /directives (FR-DIR-005, FR-DIR-009).
 *
 * @mixin Directive
 */
class DirectiveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type_category' => $this->type_category,
            'description' => $this->description,
            'status' => $this->status,
            'target_completion_date' => $this->target_completion_date,
            'last_progress_update_at' => $this->last_progress_update_at,
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'target_user' => $this->whenLoaded('targetUser', fn () => [
                'id' => $this->targetUser->id,
                'full_name' => $this->targetUser->full_name,
            ]),
            'issued_by' => $this->whenLoaded('issuedBy', fn () => [
                'id' => $this->issuedBy->id,
                'full_name' => $this->issuedBy->full_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
