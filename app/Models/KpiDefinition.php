<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\KpiDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiDefinition extends Model
{
    /** @use HasFactory<KpiDefinitionFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'name',
        'description',
        'unit',
        'calculation_method',
        'data_source',
        'reporting_frequency',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function kpiProfileDefinitions(): HasMany
    {
        return $this->hasMany(KpiProfileDefinition::class);
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
