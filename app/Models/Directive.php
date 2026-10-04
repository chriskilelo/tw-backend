<?php

namespace App\Models;

use App\Enums\DirectiveStatus;
use App\Models\Concerns\HasMinistryScope;
use App\Services\DirectiveService;
use Database\Factories\DirectiveFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The derived flags below (due state, overdue, stale, days until due) are
 * the ONE implementation used by the resources, the list filters, the
 * summary dashboards and DashboardService, so every screen agrees.
 * Date comparisons are made against today's calendar date, never with
 * isPast() on the date cast: a directive is not overdue on its own due date
 * (FR-DIR-003, BR-017).
 */
class Directive extends Model
{
    public const string DUE_COMPLETED = 'completed';

    public const string DUE_CANCELLED = 'cancelled';

    public const string DUE_NO_DATE = 'no_date';

    public const string DUE_OVERDUE = 'overdue';

    public const string DUE_APPROACHING = 'approaching';

    public const string DUE_ON_TRACK = 'on_track';

    /** @use HasFactory<DirectiveFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'mission_id',
        'target_user_id',
        'issued_by_user_id',
        'type_category',
        'description',
        'target_completion_date',
        'status',
        'completion_summary',
        'last_progress_update_at',
    ];

    protected function casts(): array
    {
        return [
            'target_completion_date' => 'date',
            'status' => DirectiveStatus::class,
            'last_progress_update_at' => 'datetime',
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

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DirectiveNote::class);
    }

    public function isOpen(): bool
    {
        return $this->status instanceof DirectiveStatus && $this->status->isOpen();
    }

    /**
     * FR-DIR-003 groupings: completed (completed or closed), cancelled,
     * then, for a directive still being worked, no_date / overdue /
     * approaching (due within DirectiveService::APPROACHING_WINDOW_DAYS,
     * today included) / on_track.
     */
    public function dueState(): string
    {
        if ($this->status instanceof DirectiveStatus && $this->status->isDelivered()) {
            return self::DUE_COMPLETED;
        }

        if ($this->status === DirectiveStatus::Cancelled) {
            return self::DUE_CANCELLED;
        }

        $daysUntilDue = $this->daysUntilDue();

        return match (true) {
            $daysUntilDue === null => self::DUE_NO_DATE,
            $daysUntilDue < 0 => self::DUE_OVERDUE,
            $daysUntilDue <= DirectiveService::APPROACHING_WINDOW_DAYS => self::DUE_APPROACHING,
            default => self::DUE_ON_TRACK,
        };
    }

    public function isOverdue(): bool
    {
        return $this->dueState() === self::DUE_OVERDUE;
    }

    /**
     * Whole calendar days from today to the target date (negative once it
     * has passed), or null when no date is set.
     */
    public function daysUntilDue(): ?int
    {
        if ($this->target_completion_date === null) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->target_completion_date->copy()->startOfDay(), false);
    }

    /**
     * FR-DIR-010: open, with no progress for more than the stale threshold.
     */
    public function isStale(): bool
    {
        return $this->isOpen()
            && $this->last_progress_update_at !== null
            && $this->last_progress_update_at->lt(now()->subDays(DirectiveService::STALE_THRESHOLD_DAYS));
    }
}
