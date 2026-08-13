<?php

namespace App\Models;

use Database\Factories\MinistryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ministry extends Model
{
    /** @use HasFactory<MinistryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'active',
        'designated_deputy_user_id',
        'designated_deputy_active',
        'acting_ps_user_id',
        'acting_ps_active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'designated_deputy_active' => 'boolean',
            'acting_ps_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function designatedDeputy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designated_deputy_user_id');
    }

    public function actingPs(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_ps_user_id');
    }

    public function missionMinistryLinks(): HasMany
    {
        return $this->hasMany(MissionMinistryLink::class);
    }

    public function masterDataEntries(): HasMany
    {
        return $this->hasMany(MasterDataEntry::class);
    }

    public function reportTemplateSections(): HasMany
    {
        return $this->hasMany(ReportTemplateSection::class);
    }

    public function kpiDefinitions(): HasMany
    {
        return $this->hasMany(KpiDefinition::class);
    }

    public function kpiProfiles(): HasMany
    {
        return $this->hasMany(KpiProfile::class);
    }

    public function referralOrganisations(): HasMany
    {
        return $this->hasMany(ReferralOrganisation::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function directives(): HasMany
    {
        return $this->hasMany(Directive::class);
    }

    public function periodicReports(): HasMany
    {
        return $this->hasMany(PeriodicReport::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }
}
