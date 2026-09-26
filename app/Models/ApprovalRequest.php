<?php

namespace App\Models;

use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalRequestType;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-AUTH-022/023, BR-027: a Principal Secretary change requested by a
 * Ministry Administrator and decided by a System Administrator. See
 * App\Services\PsApprovalService.
 */
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'type',
        'status',
        'requested_by_user_id',
        'subject_user_id',
        'payload',
        'decided_by_user_id',
        'decided_at',
        'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => ApprovalRequestType::class,
            'status' => ApprovalRequestStatus::class,
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id')->withTrashed();
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id')->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id')->withTrashed();
    }
}
