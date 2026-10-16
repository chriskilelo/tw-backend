<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\Ministry;
use App\Models\User;

/**
 * FR-ALERT-* role requirements (CLAUDE.md Section 10, API-001 Section 6).
 * BasePolicy::before() already denies every non-view ability for the four
 * BR-020 read-only roles before any method here runs.
 *
 * Mirrors the role grants already encoded in
 * App\Services\PermissionCatalogueService::catalogue(): alert.create/edit
 * -> Ministry Attache; alert.delegate -> Ministry PS, Acting PS;
 * alert.acknowledge/feedback.post -> Ministry HQ Officer, Ministry PS,
 * Designated Deputy, Acting PS. Acknowledging and posting feedback are
 * also record-level (FR-ALERT-009/010: "an assigned recipient"): see
 * isAlertRecipient().
 */
class AlertPolicy extends BasePolicy
{
    private const array DELEGATING_ROLES = ['Ministry PS', 'Acting PS'];

    /**
     * Roles that act as the department's primary recipient of routed
     * alerts (FR-ALERT-005, FR-ALERT-008), on any alert of the department.
     *
     * @var array<int, string>
     */
    private const array PRIMARY_RECIPIENT_ROLES = ['Ministry PS', 'Acting PS', 'Designated Deputy'];

    /**
     * FR-SDT-002: who an alert may be delegated to — the directors and the
     * HQ trade officers of the alert's own department. DelegateAlertRequest
     * enforces it.
     *
     * @var array<int, string>
     */
    public const array DELEGATE_ROLES = ['Ministry HQ Officer', 'Ministry HQ Director', 'Ministry Publishing Authority'];

    /**
     * Every ministry user lists their department's alerts (the global scope
     * isolates the ministry). The governance roles bypass that scope, so
     * they are confined here and by Alert::visibleTo(): a Head or Deputy
     * Head of Mission lists their own mission's alerts (FR-HOM-001), the MFA
     * roles none (FR-MFA-001 AC2).
     */
    public function viewAny(User $user): bool
    {
        return ! $this->isMfaRole($user);
    }

    /**
     * FR-HOM-001 AC2: a Head or Deputy Head of Mission reads an alert of
     * their own mission in full, feedback thread included; never another
     * mission's. The MFA roles never read an alert's content.
     */
    public function view(User $user, Alert $alert): bool
    {
        return $this->governanceReadAccess($user, $alert->mission_id) ?? true;
    }

    public function create(User $user): bool
    {
        return $user->role?->name === 'Ministry Attache';
    }

    public function update(User $user, Alert $alert): bool
    {
        return $user->role?->name === 'Ministry Attache' && $alert->submitted_by_user_id === $user->id;
    }

    public function uploadAttachment(User $user, Alert $alert): bool
    {
        return $this->update($user, $alert);
    }

    public function delegate(User $user, Alert $alert): bool
    {
        return in_array($user->role?->name, self::DELEGATING_ROLES, true);
    }

    /**
     * FR-ALERT-009: only a recipient of this alert (isAlertRecipient()).
     */
    public function acknowledge(User $user, Alert $alert): bool
    {
        return $this->isAlertRecipient($user, $alert);
    }

    /**
     * FR-ALERT-010: a recipient of this alert, or the attache who submitted
     * it replying in its own thread — never an attache of another alert.
     */
    public function postFeedback(User $user, Alert $alert): bool
    {
        if ($user->role?->name === 'Ministry Attache') {
            return $alert->submitted_by_user_id === $user->id;
        }

        return $this->isAlertRecipient($user, $alert);
    }

    /**
     * FR-ALERT-009/010, API-001 Section 6 ("Assigned Ministry HQ Officer or
     * delegate"): the PS, Acting PS and Designated Deputy roles act as the
     * department's primary recipient on any of its alerts. A Ministry HQ
     * Officer acts only on an alert assigned to them, or as the department's
     * active designated deputy (FR-ALERT-008), to whom alerts are routed
     * without an assignment. Every other role is no recipient. A list that
     * eager-loads the alerts' ministry (the HQ workspace) answers the deputy
     * check without a query per row.
     */
    private function isAlertRecipient(User $user, Alert $alert): bool
    {
        $role = $user->role?->name;

        if (in_array($role, self::PRIMARY_RECIPIENT_ROLES, true)) {
            return true;
        }

        if ($role !== 'Ministry HQ Officer') {
            return false;
        }

        if ($alert->assigned_to_user_id !== null && $alert->assigned_to_user_id === $user->id) {
            return true;
        }

        $ministry = $alert->relationLoaded('ministry') ? $alert->ministry : Ministry::query()->find($alert->ministry_id);

        return $ministry !== null
            && $ministry->designated_deputy_active
            && $ministry->designated_deputy_user_id === $user->id;
    }
}
