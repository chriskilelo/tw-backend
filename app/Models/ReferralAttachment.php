<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ReferralAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralAttachment extends Model
{
    /** @use HasFactory<ReferralAttachmentFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'referral_entry_id',
        'file_path',
        'original_filename',
        'file_size_bytes',
        'mime_type',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
        ];
    }

    public function ministryScopeColumn(): ?string
    {
        return null;
    }

    public function ministryScopeRelation(): ?string
    {
        return 'referralEntry.inquiry';
    }

    public function referralEntry(): BelongsTo
    {
        return $this->belongsTo(ReferralEntry::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
