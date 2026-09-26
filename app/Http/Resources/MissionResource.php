<?php

namespace App\Http\Resources;

use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /missions is available to any authenticated user for dropdowns
 * (session 07 task 4); host_country, time_zone, timestamps, and the
 * mission_ministry_links roster are additionally exposed only when the
 * requesting user is a System Administrator.
 *
 * @mixin Mission
 */
class MissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isSystemAdministrator = $request->user()?->role?->name === 'System Administrator';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'active' => $this->active,
            'host_country' => $this->when($isSystemAdministrator, $this->host_country),
            'time_zone' => $this->when($isSystemAdministrator, $this->time_zone),
            // Loaded only for administrators (Admin\MissionController::index(),
            // already narrowed to a Ministry Administrator's own department).
            'mission_ministry_links' => $this->when(
                $this->relationLoaded('missionMinistryLinks'),
                fn () => $this->missionMinistryLinks->map(fn ($link) => [
                    'ministry_id' => $link->ministry_id,
                    'active_attache_user_id' => $link->active_attache_user_id,
                    'active_attache' => $link->relationLoaded('activeAttache') && $link->activeAttache
                        ? ['id' => $link->activeAttache->id, 'full_name' => $link->activeAttache->full_name]
                        : null,
                ]),
            ),
            'created_at' => $this->when($isSystemAdministrator, $this->created_at),
            'updated_at' => $this->when($isSystemAdministrator, $this->updated_at),
        ];
    }
}
