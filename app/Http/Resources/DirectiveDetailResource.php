<?php

namespace App\Http\Resources;

use App\Models\Directive;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /directives/{id}: full directive detail plus its notes (FR-DIR-005,
 * API-001 Section 8).
 *
 * @mixin Directive
 */
class DirectiveDetailResource extends JsonResource
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
            'completion_summary' => $this->completion_summary,
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
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($note) => [
                'id' => $note->id,
                'content' => $note->content,
                'authored_by' => $note->relationLoaded('authoredBy') && $note->authoredBy ? [
                    'id' => $note->authoredBy->id,
                    'full_name' => $note->authoredBy->full_name,
                ] : null,
                'created_at' => $note->created_at,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
