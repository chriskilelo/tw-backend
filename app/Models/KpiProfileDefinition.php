<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\KpiProfileDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiProfileDefinition extends Model
{
    /** @use HasFactory<KpiProfileDefinitionFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'kpi_profile_id',
        'kpi_definition_id',
    ];

    public function ministryScopeColumn(): ?string
    {
        return null;
    }

    public function ministryScopeRelation(): ?string
    {
        return 'kpiProfile';
    }

    public function kpiProfile(): BelongsTo
    {
        return $this->belongsTo(KpiProfile::class);
    }

    public function kpiDefinition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class);
    }
}
