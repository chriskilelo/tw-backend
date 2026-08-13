<?php

namespace App\Models;

use App\Enums\SectionType;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ReportTemplateSectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportTemplateSection extends Model
{
    /** @use HasFactory<ReportTemplateSectionFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'version',
        'effective_date',
        'section_order',
        'section_title',
        'section_type',
        'column_schema',
        'guidance_text',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'version' => 'integer',
            'section_order' => 'integer',
            'section_type' => SectionType::class,
            'column_schema' => 'array',
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function reportSections(): HasMany
    {
        return $this->hasMany(ReportSection::class);
    }
}
