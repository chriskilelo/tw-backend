<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\UserStatus;
use App\Models\Alert;
use App\Models\AlertFeedback;
use App\Models\AlertVersion;
use App\Models\Ministry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-ALERT-002 to 013, FR-SDT-003: business logic for
 * the Intelligence Alert Engine. Alert\Http\Controllers\Api\Alerts\AlertController
 * stays thin and delegates every mutation here.
 */
class AlertService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated by StoreAlertRequest
     *                                      against the configured field schema (BR-011).
     */
    public function submitAlert(array $data, User $submitter): Alert
    {
        return DB::transaction(function () use ($data, $submitter) {
            $alert = Alert::create([
                'ministry_id' => $submitter->ministry_id,
                'mission_id' => $submitter->mission_id,
                'submitted_by_user_id' => $submitter->id,
                'reference_number' => $this->generateReferenceNumber(),
                'country' => $data['country'],
                'sector' => $data['sector'] ?? null,
                'product_category' => $data['product_category'] ?? null,
                'product_description' => $data['product_description'] ?? null,
                'intelligence_type' => $data['intelligence_type'],
                'intelligence_source' => $data['intelligence_source'] ?? null,
                'urgency' => $data['urgency'] ?? null,
                'confidence_rating' => $data['confidence_rating'] ?? null,
                'tags' => $data['tags'] ?? null,
                'status' => AlertStatus::New,
            ]);

            $this->routeAlert($alert);

            return $alert;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Already validated by UpdateAlertRequest.
     */
    public function editAlert(Alert $alert, array $data, User $editor): void
    {
        DB::transaction(function () use ($alert, $data, $editor): void {
            AlertVersion::create([
                'alert_id' => $alert->id,
                'snapshot' => $alert->getAttributes(),
                'edited_by_user_id' => $editor->id,
            ]);

            $alert->forceFill(array_intersect_key($data, array_flip([
                'country', 'sector', 'product_category', 'product_description',
                'intelligence_type', 'intelligence_source', 'urgency',
                'confidence_rating', 'tags',
            ])))->save();
        });

        $this->notifyPreviouslyInvolvedHqUsers($alert, $editor);
    }

    /**
     * @param  array<int, string>  $userIds  FR-ALERT-007 allows delegating to
     *                                       multiple users, but alerts carries a
     *                                       single assigned_to_user_id column
     *                                       (CLAUDE.md Section 6); the first id
     *                                       is persisted as the primary
     *                                       assignee, every id is notified.
     */
    public function delegateAlert(Alert $alert, array $userIds, User $ps): void
    {
        if ($userIds === []) {
            throw new InvalidArgumentException('At least one delegate user id is required.');
        }

        $alert->forceFill([
            'assigned_to_user_id' => $userIds[0],
            'status' => AlertStatus::Assigned,
        ])->save();

        foreach (User::query()->whereIn('id', $userIds)->get() as $delegate) {
            $this->notificationService->notify(
                $delegate,
                'alert_routed',
                "Alert {$alert->reference_number} has been delegated to you by {$ps->full_name}.",
                "/alerts/{$alert->id}",
            );
        }
    }

    public function acknowledgeAlert(Alert $alert, User $recipient): void
    {
        $alert->forceFill(['status' => AlertStatus::Acknowledged])->save();

        $submitter = $alert->submittedBy;

        if ($submitter !== null) {
            $this->notificationService->notify(
                $submitter,
                'alert_acknowledged',
                "Alert {$alert->reference_number} has been acknowledged by {$recipient->full_name}.",
                "/alerts/{$alert->id}",
            );
        }
    }

    public function postFeedback(Alert $alert, string $content, User $poster): AlertFeedback
    {
        $feedback = AlertFeedback::create([
            'alert_id' => $alert->id,
            'posted_by_user_id' => $poster->id,
            'content' => $content,
        ]);

        $submitter = $alert->submittedBy;

        if ($submitter !== null && $submitter->id !== $poster->id) {
            $this->notificationService->notify(
                $submitter,
                'alert_feedback_posted',
                "New feedback has been posted on alert {$alert->reference_number}.",
                "/alerts/{$alert->id}",
            );
        }

        return $feedback;
    }

    /**
     * FR-SDT-003, FR-ALERT-008: eligibility is governed by permission, not
     * job title, so this does not restrict $deputy to a specific role — it
     * only requires the deputy to be an active account in a department.
     * ADR-006: the activator may be the department's PS, Acting PS or
     * Ministry Administrator (all pinned to their own department), or a
     * System Administrator (any department).
     */
    public function activateDesignatedDeputy(User $activator, User $deputy): void
    {
        if ($deputy->ministry_id === null || $deputy->status === UserStatus::Deactivated) {
            throw new InvalidArgumentException('The designated deputy must be an active account in a department.');
        }

        if (! AdministrationService::isSystemAdministrator($activator) && $deputy->ministry_id !== $activator->ministry_id) {
            throw new InvalidArgumentException('The designated deputy must belong to your own department.');
        }

        Ministry::query()->whereKey($deputy->ministry_id)->update([
            'designated_deputy_user_id' => $deputy->id,
            'designated_deputy_active' => true,
        ]);

        $this->auditService->record($activator, 'designated_deputy.activated', 'ministry', $deputy->ministry_id, ['deputy_user_id' => $deputy->id], null, $deputy->ministry_id);
    }

    /**
     * FR-SDT-003: switches the fallback off; alerts route to the PS again.
     */
    public function deactivateDesignatedDeputy(User $activator, Ministry $ministry): void
    {
        if (! AdministrationService::isSystemAdministrator($activator) && $ministry->id !== $activator->ministry_id) {
            throw new InvalidArgumentException('You can only manage the Designated Deputy of your own department.');
        }

        if (! $ministry->designated_deputy_active) {
            throw new InvalidArgumentException('No Designated Deputy is currently active for this department.');
        }

        $previousDeputyId = $ministry->designated_deputy_user_id;

        $ministry->forceFill([
            'designated_deputy_user_id' => null,
            'designated_deputy_active' => false,
        ])->save();

        $this->auditService->record($activator, 'designated_deputy.deactivated', 'ministry', $ministry->id, ['deputy_user_id' => $previousDeputyId], null, $ministry->id);
    }

    /**
     * FR-ALERT-005, BR-012: routes to the ministry's active Designated
     * Deputy (FR-ALERT-008) when one is active, otherwise to the ministry's
     * Ministry PS. No notification is dispatched if neither is found.
     */
    private function routeAlert(Alert $alert): void
    {
        $recipient = $this->resolveRoutingRecipient($alert);

        if ($recipient === null) {
            return;
        }

        $this->notificationService->notify(
            $recipient,
            'alert_routed',
            "A new alert ({$alert->reference_number}) has been routed to you.",
            "/alerts/{$alert->id}",
        );
    }

    private function resolveRoutingRecipient(Alert $alert): ?User
    {
        $ministry = Ministry::query()->find($alert->ministry_id);

        if ($ministry?->designated_deputy_active && $ministry->designated_deputy_user_id !== null) {
            return $ministry->designatedDeputy;
        }

        return User::query()
            ->where('ministry_id', $alert->ministry_id)
            ->whereHas('role', fn ($query) => $query->where('name', 'Ministry PS'))
            ->first();
    }

    private function notifyPreviouslyInvolvedHqUsers(Alert $alert, User $editor): void
    {
        $recipientIds = $alert->feedback()->pluck('posted_by_user_id');

        if ($alert->assigned_to_user_id !== null) {
            $recipientIds->push($alert->assigned_to_user_id);
        }

        $recipients = User::query()
            ->whereIn('id', $recipientIds->unique())
            ->where('id', '!=', $editor->id)
            ->get();

        foreach ($recipients as $recipient) {
            $this->notificationService->notify(
                $recipient,
                'alert_edited',
                "Alert {$alert->reference_number} has been edited by {$editor->full_name}.",
                "/alerts/{$alert->id}",
            );
        }
    }

    private function generateReferenceNumber(): string
    {
        $prefix = 'ALT-'.now()->format('Ym').'-';

        // lockForUpdate() cannot be combined with an aggregate count() query
        // on PostgreSQL ("FOR UPDATE is not allowed with aggregate
        // functions"), so the matching rows are locked and counted in PHP.
        $sequence = Alert::withoutGlobalScopes()
            ->where('reference_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('id')
            ->count() + 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
