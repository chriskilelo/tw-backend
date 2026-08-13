<?php

namespace App\Models;

use Database\Factories\MissionMinistryLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionMinistryLink extends Model
{
    /** @use HasFactory<MissionMinistryLinkFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'mission_id',
        'ministry_id',
        'active_attache_user_id',
    ];

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function activeAttache(): BelongsTo
    {
        return $this->belongsTo(User::class, 'active_attache_user_id');
    }
}
