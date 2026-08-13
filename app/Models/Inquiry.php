<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use App\Enums\InquirySubType;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\InquiryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inquiry extends Model
{
    /** @use HasFactory<InquiryFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'mission_id',
        'logged_by_user_id',
        'reference_number',
        'category',
        'sub_type',
        'inquirer_name',
        'inquirer_organisation',
        'inquirer_email',
        'inquirer_phone',
        'product_or_sector',
        'description',
        'date_received',
        'status',
        'high_value_flag',
        'high_value_justification',
        'resolution_summary',
        'closed_at',
        'linked_inquiry_id',
    ];

    protected function casts(): array
    {
        return [
            'sub_type' => InquirySubType::class,
            'status' => InquiryStatus::class,
            'date_received' => 'date',
            'high_value_flag' => 'boolean',
            'closed_at' => 'datetime',
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

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }

    public function linkedInquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class, 'linked_inquiry_id');
    }

    public function linkedFromInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'linked_inquiry_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(InquiryNote::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InquiryEvent::class);
    }

    public function referralEntries(): HasMany
    {
        return $this->hasMany(ReferralEntry::class);
    }
}
