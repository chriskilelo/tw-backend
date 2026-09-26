<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'ministry_id' => $this->ministry_id,
            'user_full_name' => $this->whenLoaded('user', fn () => $this->user?->full_name),
            'action' => $this->action,
            'affected_entity_type' => $this->affected_entity_type,
            'affected_entity_id' => $this->affected_entity_id,
            'changes' => $this->changes,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}
