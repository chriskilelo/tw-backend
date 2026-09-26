<?php

namespace App\Http\Resources;

use App\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'ministry' => $this->whenLoaded('ministry', fn () => [
                'id' => $this->ministry->id,
                'name' => $this->ministry->name,
            ]),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->personSummary($this->requestedBy)),
            'subject_user' => $this->whenLoaded('subjectUser', fn () => $this->personSummary($this->subjectUser)),
            'payload' => $this->payload,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->personSummary($this->decidedBy)),
            'decided_at' => $this->decided_at,
            'decision_reason' => $this->decision_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array{id: string, full_name: string}|null
     */
    private function personSummary(mixed $user): ?array
    {
        return $user ? ['id' => $user->id, 'full_name' => $user->full_name] : null;
    }
}
