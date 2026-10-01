<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\KpiProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiProfile extends Model
{
    /** @use HasFactory<KpiProfileFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'name',
    ];

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function kpiProfileDefinitions(): HasMany
    {
        return $this->hasMany(KpiProfileDefinition::class);
    }

    public function kpiProfileMissions(): HasMany
    {
        return $this->hasMany(KpiProfileMission::class);
    }

    /**
     * FR-KPI-003: the profile's default targets, every version.
     */
    public function kpiProfileTargets(): HasMany
    {
        return $this->hasMany(KpiProfileTarget::class);
    }
}
