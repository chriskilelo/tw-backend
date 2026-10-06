<?php

namespace App\Models;

use App\Enums\PeriodicReportStatus;
use App\Models\Concerns\HasMinistryScope;
use App\Policies\ReportPolicy;
use Database\Factories\PeriodicReportFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The deadline and timeliness helpers below are the ONE implementation used
 * by the resources, the list filters, the compliance dashboard and the
 * reminders, so every screen agrees (BR-010, FR-RPT-016, FR-RPT-018).
 */
class PeriodicReport extends Model
{
    public const string COMPLIANCE_ON_TIME = 'submitted_on_time';

    public const string COMPLIANCE_LATE = 'submitted_late';

    public const string COMPLIANCE_DRAFT = 'draft_in_progress';

    public const string COMPLIANCE_NOT_STARTED = 'not_started';

    /** @use HasFactory<PeriodicReportFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'mission_id',
        'authored_by_user_id',
        'reporting_period_label',
        'period_start_date',
        'period_end_date',
        'template_version',
        'status',
        'submitted_at',
        'is_late',
    ];

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'template_version' => 'integer',
            'status' => PeriodicReportStatus::class,
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
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

    public function authoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by_user_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ReportSection::class);
    }

    /**
     * The reports $user may read (ReportPolicy::view() at query level):
     * a Ministry Attache every report of their own mission (BR-001), drafts
     * included; the HQ review roles the department's submitted reports
     * (FR-RPT-017, FR-SDT-007, FR-SDT-015); a Head or Deputy Head of Mission
     * the submitted reports of their mission, from every department
     * (FR-HOM-001 AC2). Anyone else sees nothing. The mission-governance
     * roles bypass ministry scoping, so the mission pin is what confines them.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $role = $user->role?->name;

        if (in_array($role, ReportPolicy::AUTHOR_ROLES, true) && $user->mission_id !== null) {
            $query->where('mission_id', $user->mission_id);

            return;
        }

        if (in_array($role, ReportPolicy::MISSION_OVERSIGHT_ROLES, true) && $user->mission_id !== null) {
            $query->where('mission_id', $user->mission_id)->where('status', PeriodicReportStatus::Submitted->value);

            return;
        }

        if (in_array($role, ReportPolicy::REVIEWER_ROLES, true)) {
            $query->where('status', PeriodicReportStatus::Submitted->value);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    public function isDraft(): bool
    {
        return $this->status === PeriodicReportStatus::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status === PeriodicReportStatus::Submitted;
    }

    /**
     * BR-010 / CLAUDE.md Section 8: the end of the 15th day of the month
     * after the period ends (period_end_date is always a month's last day,
     * so adding a day lands on the 1st).
     */
    public static function deadlineForPeriodEnd(Carbon $periodEndDate): Carbon
    {
        return $periodEndDate->copy()->startOfDay()->addDay()->addDays(14)->endOfDay();
    }

    public function deadline(): Carbon
    {
        return self::deadlineForPeriodEnd(Carbon::parse($this->period_end_date));
    }

    /**
     * Whole calendar days from today to the deadline day, negative once it
     * has passed. Computed here, in the application timezone that decides
     * lateness, so every screen counts the same days the API judges by.
     */
    public function daysToDeadline(): int
    {
        return (int) Carbon::today()->diffInDays($this->deadline()->startOfDay(), false);
    }

    /**
     * A draft whose deadline has passed: it will be flagged late whenever it
     * is submitted.
     */
    public function isOverdue(): bool
    {
        return $this->isDraft() && Carbon::now()->greaterThan($this->deadline());
    }

    /**
     * FR-RPT-016 AC1 "display the number of days overdue": calendar days
     * from the deadline to the submission (a late report) or to today (an
     * overdue draft); null when the report is not late.
     */
    public function daysOverdue(): ?int
    {
        $deadlineDay = $this->deadline()->startOfDay();

        if ($this->isSubmitted()) {
            return $this->is_late && $this->submitted_at !== null
                ? max(1, (int) $deadlineDay->diffInDays($this->submitted_at->copy()->startOfDay()))
                : null;
        }

        return $this->isOverdue() ? max(1, (int) $deadlineDay->diffInDays(Carbon::today())) : null;
    }

    /**
     * FR-RPT-018: submitted_on_time, submitted_late or draft_in_progress
     * (a period with no report at all is not_started, decided by the caller).
     */
    public function complianceStatus(): string
    {
        return match (true) {
            ! $this->isSubmitted() => self::COMPLIANCE_DRAFT,
            $this->is_late => self::COMPLIANCE_LATE,
            default => self::COMPLIANCE_ON_TIME,
        };
    }
}
