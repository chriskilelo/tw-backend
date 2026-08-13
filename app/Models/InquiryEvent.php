<?php

namespace App\Models;

use App\Enums\InquiryEventType;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\InquiryEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryEvent extends Model
{
    /** @use HasFactory<InquiryEventFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'inquiry_id',
        'event_type',
        'note',
        'logged_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => InquiryEventType::class,
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

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }
}
