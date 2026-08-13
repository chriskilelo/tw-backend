<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\LanguagePreference;
use App\Enums\UserStatus;
use App\Jobs\SendPasswordResetEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'full_name', 'email', 'password', 'role_id', 'mission_id', 'ministry_id',
    'status', 'failed_login_attempts', 'last_login_at', 'language_preference',
    'email_notification_preferences', 'acting_ps_original_role_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'language_preference' => LanguagePreference::class,
            'email_notification_preferences' => 'array',
            'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function notificationsReceived(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_user_id');
    }

    public function activeMissionMinistryLinks(): HasMany
    {
        return $this->hasMany(MissionMinistryLink::class, 'active_attache_user_id');
    }

    public function submittedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'submitted_by_user_id');
    }

    public function assignedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'assigned_to_user_id');
    }

    public function editedAlertVersions(): HasMany
    {
        return $this->hasMany(AlertVersion::class, 'edited_by_user_id');
    }

    public function postedAlertFeedback(): HasMany
    {
        return $this->hasMany(AlertFeedback::class, 'posted_by_user_id');
    }

    public function uploadedAlertAttachments(): HasMany
    {
        return $this->hasMany(AlertAttachment::class, 'uploaded_by_user_id');
    }

    public function loggedInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'logged_by_user_id');
    }

    public function authoredInquiryNotes(): HasMany
    {
        return $this->hasMany(InquiryNote::class, 'authored_by_user_id');
    }

    public function loggedInquiryEvents(): HasMany
    {
        return $this->hasMany(InquiryEvent::class, 'logged_by_user_id');
    }

    public function createdReferralEntries(): HasMany
    {
        return $this->hasMany(ReferralEntry::class, 'created_by_user_id');
    }

    public function uploadedReferralAttachments(): HasMany
    {
        return $this->hasMany(ReferralAttachment::class, 'uploaded_by_user_id');
    }

    public function issuedDirectives(): HasMany
    {
        return $this->hasMany(Directive::class, 'issued_by_user_id');
    }

    public function targetedDirectives(): HasMany
    {
        return $this->hasMany(Directive::class, 'target_user_id');
    }

    public function authoredDirectiveNotes(): HasMany
    {
        return $this->hasMany(DirectiveNote::class, 'authored_by_user_id');
    }

    public function authoredPeriodicReports(): HasMany
    {
        return $this->hasMany(PeriodicReport::class, 'authored_by_user_id');
    }

    public function createdContentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'created_by_user_id');
    }

    public function approvedContentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'approved_by_user_id');
    }

    public function createdContentVersions(): HasMany
    {
        return $this->hasMany(ContentVersion::class, 'created_by_user_id');
    }

    public function kpiTargetsSet(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'set_by_user_id');
    }

    public function kpiActualsEntered(): HasMany
    {
        return $this->hasMany(KpiActual::class, 'entered_by_user_id');
    }

    /**
     * FR-AUTH-011: routes the password broker's reset link through the
     * queued SendPasswordResetEmail job (CLAUDE.md Section 12) instead of
     * Laravel's default synchronous ResetPassword notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        SendPasswordResetEmail::dispatch($this->email, $token);
    }

    /**
     * NFR-DATA-003: deactivating a user soft-deletes the row (BR-002 data
     * retention, never a hard delete); System Administrator admin routes
     * (the only routes binding {user}) must still resolve a deactivated
     * account so it can be viewed, reactivated, or resent an activation
     * email.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
