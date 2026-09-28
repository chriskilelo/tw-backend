<?php

namespace App\Http\Resources;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /inquiries/{id}: full inquiry detail plus its notes, events timeline
 * and referral history (FR-INQ-002, FR-REF-006, API-001 Section 7).
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
            'referrals' => $this->whenLoaded('referralEntries', fn () => $this->referralEntries
                ->sortBy('referral_date')
                ->values()
                ->map(fn ($referral) => [
                    'id' => $referral->id,
                    'referral_organisation' => $referral->relationLoaded('referralOrganisation') && $referral->referralOrganisation ? [
                        'id' => $referral->referralOrganisation->id,
                        'name' => $referral->referralOrganisation->name,
                    ] : null,
                    'contact_person' => $referral->contact_person,
                    'referral_date' => $referral->referral_date,
                    'referral_method' => $referral->referral_method,
                    'reference_number' => $referral->reference_number,
                    'remarks' => $referral->remarks,
                    'created_by' => $referral->relationLoaded('createdBy') && $referral->createdBy ? [
                        'id' => $referral->createdBy->id,
                        'full_name' => $referral->createdBy->full_name,
                    ] : null,
                    'attachments' => $referral->relationLoaded('attachments') ? $referral->attachments->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'file_size_bytes' => $attachment->file_size_bytes,
                        'mime_type' => $attachment->mime_type,
                    ])->values() : [],
                    'created_at' => $referral->created_at,
                ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
