<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'status' => $this->status,
            'role' => $this->whenLoaded('role', fn () => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'display_title' => $this->role->display_title,
            ]),
            'mission' => $this->whenLoaded('mission', fn () => $this->mission ? [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ] : null),
            'ministry' => $this->whenLoaded('ministry', fn () => $this->ministry ? [
                'id' => $this->ministry->id,
                'name' => $this->ministry->name,
            ] : null),
            'home_ministry' => $this->whenLoaded('homeMinistry', fn () => $this->homeMinistry ? [
                'id' => $this->homeMinistry->id,
                'name' => $this->homeMinistry->name,
            ] : null),
            'language_preference' => $this->language_preference,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
