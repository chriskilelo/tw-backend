<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trigger_type' => $this->trigger_type,
            'message' => $this->message,
            'link' => $this->link,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
