<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\InquiryNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryNote extends Model
{
    /** @use HasFactory<InquiryNoteFactory> */
    use HasFactory, HasMinistryScope, HasUuids, IsAppendOnly;

    const UPDATED_AT = null;

    protected $fillable = [
        'inquiry_id',
        'authored_by_user_id',
        'content',
    ];

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

    public function authoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by_user_id');
    }
}
