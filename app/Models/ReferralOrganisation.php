<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ReferralOrganisationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralOrganisation extends Model
{
    /** @use HasFactory<ReferralOrganisationFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function referralEntries(): HasMany
    {
        return $this->hasMany(ReferralEntry::class);
    }
}
