<?php

namespace App\Http\Resources;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /inquiries/{id}: full inquiry detail plus its notes and events
 * timeline (FR-INQ-002, API-001 Section 7).
 *
 * @mixin Inquiry
 */
class InquiryDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'category' => $this->category,
            'sub_type' => $this->sub_type,
            'inquirer_name' => $this->inquirer_name,
            'inquirer_organisation' => $this->inquirer_organisation,
            'inquirer_email' => $this->inquirer_email,
            'inquirer_phone' => $this->inquirer_phone,
            'product_or_sector' => $this->product_or_sector,
            'description' => $this->description,
            'date_received' => $this->date_received,
            'status' => $this->status,
            'high_value_flag' => $this->high_value_flag,
            'high_value_justification' => $this->high_value_justification,
            'resolution_summary' => $this->resolution_summary,
            'closed_at' => $this->closed_at,
            'linked_inquiry' => $this->whenLoaded('linkedInquiry', fn () => $this->linkedInquiry === null ? null : [
                'id' => $this->linkedInquiry->id,
                'reference_number' => $this->linkedInquiry->reference_number,
                'mission' => $this->linkedInquiry->relationLoaded('mission') ? $this->linkedInquiry->mission?->name : null,
            ]),
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'logged_by' => $this->whenLoaded('loggedBy', fn () => [
                'id' => $this->loggedBy->id,
                'full_name' => $this->loggedBy->full_name,
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
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'note' => $event->note,
                'logged_by' => $event->relationLoaded('loggedBy') && $event->loggedBy ? [
                    'id' => $event->loggedBy->id,
                    'full_name' => $event->loggedBy->full_name,
                ] : null,
                'created_at' => $event->created_at,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
