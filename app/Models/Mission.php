<?php

namespace App\Models;

use Database\Factories\MissionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mission extends Model
{
    /** @use HasFactory<MissionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'city',
        'host_country',
        'time_zone',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function missionMinistryLinks(): HasMany
    {
        return $this->hasMany(MissionMinistryLink::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function directives(): HasMany
    {
        return $this->hasMany(Directive::class);
    }

    public function periodicReports(): HasMany
    {
        return $this->hasMany(PeriodicReport::class);
    }

    public function kpiTargets(): HasMany
    {
        return $this->hasMany(KpiTarget::class);
    }

    public function kpiActuals(): HasMany
    {
        return $this->hasMany(KpiActual::class);
    }
}
