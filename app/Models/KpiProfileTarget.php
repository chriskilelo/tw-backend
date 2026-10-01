<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\KpiProfileTargetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-KPI-003: a KPI Profile's default target for one KPI and performance
 * cycle, applied to every mission on the profile that has no
 * mission-specific KpiTarget for that cycle. Versioned like KpiTarget
 * (BR-019): rows are only ever inserted, and the latest one wins.
 */
class KpiProfileTarget extends Model
{
    /** @use HasFactory<KpiProfileTargetFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'kpi_profile_id',
        'kpi_definition_id',
        'performance_cycle_label',
        'cycle_start_date',
        'target_value',
        'note',
        'set_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_start_date' => 'date',
            'target_value' => 'decimal:2',
        ];
    }

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

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }
}
