<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ReportSectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSection extends Model
{
    /** @use HasFactory<ReportSectionFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'periodic_report_id',
        'report_template_section_id',
        'content',
    ];

    public function ministryScopeColumn(): ?string
    {
        return null;
    }

    public function ministryScopeRelation(): ?string
    {
        return 'periodicReport';
    }

    public function periodicReport(): BelongsTo
    {
        return $this->belongsTo(PeriodicReport::class);
    }

    public function reportTemplateSection(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateSection::class);
    }

    public function dataRows(): HasMany
    {
        return $this->hasMany(ReportDataRow::class);
    }
}
