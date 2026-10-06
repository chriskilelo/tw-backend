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
    public const int DEFAULT_MAX_ROWS = 200;

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
        'table_config',
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
            'table_config' => 'array',
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

    /**
     * FR-RPT-007: the configured columns, each {name, type, mandatory?,
     * options?}. Malformed entries (no name) are dropped rather than
     * trusted.
     *
     * @return array<int, array{name: string, type: string, mandatory: bool, options: array<int, string>|null}>
     */
    public function columns(): array
    {
        return collect($this->column_schema ?? [])
            ->filter(fn (mixed $column): bool => is_array($column) && is_string($column['name'] ?? null) && $column['name'] !== '')
            ->map(fn (array $column): array => [
                'name' => $column['name'],
                'type' => is_string($column['type'] ?? null) ? $column['type'] : 'text',
                'mandatory' => (bool) ($column['mandatory'] ?? false),
                'options' => is_array($column['options'] ?? null) ? array_values(array_map('strval', $column['options'])) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * FR-RPT-008: the master data category whose active entries become the
     * pre-populated rows of every new report instance, or null.
     */
    public function prepopulateCategory(): ?string
    {
        $category = $this->table_config['prepopulate']['master_data_category'] ?? null;

        return is_string($category) && $category !== '' ? $category : null;
    }

    /**
     * FR-RPT-008: the columns a pre-populated entry fills ("CODE — Label"
     * splits across them in order). They carry labels, not attache-entered
     * values, so they never count towards a section's completion.
     *
     * @return array<int, string>
     */
    public function labelColumns(): array
    {
        $columns = $this->table_config['prepopulate']['label_columns'] ?? [];

        return is_array($columns) ? array_values(array_filter($columns, 'is_string')) : [];
    }

    /**
     * FR-RPT-009: the auto-calculated total row, or null when the section
     * has none.
     *
     * @return array{label: string, label_column: string, sum_columns: array<int, string>, exclude_labels: array<int, string>}|null
     */
    public function totalConfig(): ?array
    {
        $total = $this->table_config['total'] ?? null;

        if (! is_array($total) || ! is_string($total['label_column'] ?? null) || ! is_array($total['sum_columns'] ?? null)) {
            return null;
        }

        return [
            'label' => is_string($total['label'] ?? null) ? $total['label'] : 'TOTAL',
            'label_column' => $total['label_column'],
            'sum_columns' => array_values(array_filter($total['sum_columns'], 'is_string')),
            'exclude_labels' => is_array($total['exclude_labels'] ?? null) ? array_values(array_filter($total['exclude_labels'], 'is_string')) : [],
        ];
    }

    /**
     * FR-RPT-007: "a minimum of 20 rows supported per table", up to the
     * configured maximum.
     */
    public function maxRows(): int
    {
        $maxRows = $this->table_config['max_rows'] ?? null;

        return is_int($maxRows) && $maxRows >= 20 ? $maxRows : self::DEFAULT_MAX_ROWS;
    }

    /**
     * A row standing in for the computed total (its label column starts with
     * the total label, e.g. the "TOTAL (auto-calculated row)" entry). It is
     * never pre-populated, carried forward or summed.
     *
     * @param  array<string, mixed>  $rowData
     */
    public function isTotalPlaceholder(array $rowData): bool
    {
        $total = $this->totalConfig();

        if ($total === null) {
            return false;
        }

        $label = $rowData[$total['label_column']] ?? null;

        return is_string($label) && str_starts_with(mb_strtoupper(trim($label)), mb_strtoupper($total['label']));
    }
}
