<?php

namespace App\Models;

use App\Enums\PeriodicReportStatus;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\PeriodicReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodicReport extends Model
{
    /** @use HasFactory<PeriodicReportFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'mission_id',
        'authored_by_user_id',
        'reporting_period_label',
        'period_start_date',
        'period_end_date',
        'template_version',
        'status',
        'submitted_at',
        'is_late',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'template_version' => 'integer',
            'status' => PeriodicReportStatus::class,
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function authoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by_user_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ReportSection::class);
    }
}
