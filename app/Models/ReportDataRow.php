<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ReportDataRowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportDataRow extends Model
{
    /** @use HasFactory<ReportDataRowFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'report_section_id',
        'row_order',
        'row_data',
    ];

    protected function casts(): array
    {
        return [
            'row_order' => 'integer',
            'row_data' => 'array',
        ];
    }

    public function ministryScopeColumn(): ?string
    {
        return null;
    }

    public function ministryScopeRelation(): ?string
    {
        return 'reportSection.periodicReport';
    }

    public function reportSection(): BelongsTo
    {
        return $this->belongsTo(ReportSection::class);
    }
}
