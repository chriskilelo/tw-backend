<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\KpiTargetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiTarget extends Model
{
    /** @use HasFactory<KpiTargetFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'mission_id',
        'kpi_definition_id',
        'performance_cycle_label',
        'cycle_start_date',
        'target_value',
        'set_by_user_id',
    ];

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
        return 'kpiDefinition';
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
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
