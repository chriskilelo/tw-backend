<?php

namespace App\Models;

use App\Enums\KpiCalculationType;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\KpiActualFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiActual extends Model
{
    /** @use HasFactory<KpiActualFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'mission_id',
        'kpi_definition_id',
        'period_label',
        'period_start_date',
        'actual_value',
        'entered_by_user_id',
        'calculation_type',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'actual_value' => 'decimal:2',
            'calculation_type' => KpiCalculationType::class,
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

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }
}
