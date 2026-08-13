<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ReferralEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralEntry extends Model
{
    /** @use HasFactory<ReferralEntryFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'inquiry_id',
        'referral_organisation_id',
        'contact_person',
        'referral_date',
        'referral_method',
        'reference_number',
        'remarks',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'referral_date' => 'date',
        ];
    }

    public function ministryScopeColumn(): ?string
    {
        return null;
    }

    public function ministryScopeRelation(): ?string
    {
        return 'inquiry';
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function referralOrganisation(): BelongsTo
    {
        return $this->belongsTo(ReferralOrganisation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReferralAttachment::class);
    }
}
