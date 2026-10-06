<?php

namespace App\Policies;

use App\Models\Inquiry;
use App\Models\User;

/**
 * FR-INQ-* role requirements (API-001 Section 7). BasePolicy::before()
 * already denies every non-view ability for the four BR-020 read-only
 * roles before any method here runs.
 *
 * Mirrors the role grants encoded in
 * App\Services\PermissionCatalogueService::catalogue(): inquiry.create,
 * inquiry.transition-status, inquiry.log-event, inquiry.close all belong
 * to Ministry Attache only. view/viewAny is left open to any authenticated
 * ministry-scoped user, matching AlertPolicy's precedent — ministry
 * isolation is enforced by the model's global scope, not this policy.
 *
 * link() (FR-INQ-019, API-001 Section 7's literal "Roles Allowed: Ministry
 * HQ Officer" for POST /inquiries/{id}/link) is deliberately NOT restricted
 * to the owning attache like the other write abilities here — this is a
 * cross-mission HQ action, not a per-mission one. Note this diverges from
 * PermissionCatalogueService's WRITE_TYPE_KEYS catalogue, which still lists
 * 'inquiry.link' under Ministry Attache (a Session 11 baseline never
 * reconciled against this endpoint) — per CLAUDE.md Section 10, the
 * literal API-001 role column is authoritative for endpoint access, the
 * catalogue is a coarser Sprint-0 working baseline.
 */
class InquiryPolicy extends BasePolicy
{
    /**
     * The governance roles bypass ministry scoping, so they are confined
     * here and by Inquiry::visibleTo(): a Head or Deputy Head of Mission
     * lists their own mission's inquiries (FR-HOM-001), the MFA roles none
     * (FR-MFA-001 AC2).
     */
    public function viewAny(User $user): bool
    {
        return ! $this->isMfaRole($user);
    }

    /**
     * FR-HOM-001 AC2: a Head or Deputy Head of Mission reads an inquiry of
     * their own mission in full, progress notes included; never another
     * mission's. The MFA roles never read an inquiry's content.
     */
    public function view(User $user, Inquiry $inquiry): bool
    {
        return $this->governanceReadAccess($user, $inquiry->mission_id) ?? true;
    }

    public function create(User $user): bool
    {
        return $user->role?->name === 'Ministry Attache';
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwningAttache($user, $inquiry);
    }

    public function transitionStatus(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwningAttache($user, $inquiry);
    }

    public function logEvent(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwningAttache($user, $inquiry);
    }

    public function addNote(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwningAttache($user, $inquiry);
    }

    public function close(User $user, Inquiry $inquiry): bool
    {
        return $this->isOwningAttache($user, $inquiry);
    }

    public function link(User $user, Inquiry $inquiry): bool
    {
        return $user->role?->name === 'Ministry HQ Officer';
    }

    private function isOwningAttache(User $user, Inquiry $inquiry): bool
    {
        return $user->role?->name === 'Ministry Attache' && $user->mission_id === $inquiry->mission_id;
    }
}
