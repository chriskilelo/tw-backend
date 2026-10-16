<?php

namespace App\Http\Resources;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * GET /alerts/{id}: full alert detail plus its feedback thread and version
 * history (FR-ALERT-006, API-001 AlertDetail schema), and allowed_actions
 * for the REQUESTING user from AlertPolicy, so the alert page and the HQ
 * workspace offer exactly what the API accepts.
 *
 * @mixin Alert
 */
class AlertDetailResource extends JsonResource
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
            'product_description' => $this->product_description,
            'intelligence_type' => $this->intelligence_type,
            'intelligence_source' => $this->intelligence_source,
            'urgency' => $this->urgency,
            'confidence_rating' => $this->confidence_rating,
            'tags' => $this->tags,
            'status' => $this->status,
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => [
                'id' => $this->submittedBy->id,
                'full_name' => $this->submittedBy->full_name,
            ]),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'full_name' => $this->assignedTo->full_name,
            ] : null),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'file_size_bytes' => $attachment->file_size_bytes,
                'mime_type' => $attachment->mime_type,
                'created_at' => $attachment->created_at,
            ])),
            'feedback' => $this->whenLoaded('feedback', fn () => $this->feedback->map(fn ($entry) => [
                'id' => $entry->id,
                'content' => $entry->content,
                'posted_by' => $entry->relationLoaded('postedBy') && $entry->postedBy ? [
                    'id' => $entry->postedBy->id,
                    'full_name' => $entry->postedBy->full_name,
                ] : null,
                'created_at' => $entry->created_at,
            ])),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($version) => [
                'id' => $version->id,
                'snapshot' => $version->snapshot,
                'edited_by_user_id' => $version->edited_by_user_id,
                'created_at' => $version->created_at,
            ])),
            'allowed_actions' => $this->allowedActions($request),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array{edit: bool, delegate: bool, acknowledge: bool, post_feedback: bool}
     */
    private function allowedActions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['edit' => false, 'delegate' => false, 'acknowledge' => false, 'post_feedback' => false];
        }

        $gate = Gate::forUser($user);

        return [
            'edit' => $gate->allows('update', $this->resource),
            'delegate' => $gate->allows('delegate', $this->resource),
            'acknowledge' => $this->status !== AlertStatus::Acknowledged && $gate->allows('acknowledge', $this->resource),
            'post_feedback' => $gate->allows('postFeedback', $this->resource),
        ];
    }
}
