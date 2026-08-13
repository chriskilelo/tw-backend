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
 */
class InquiryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return true;
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

    private function isOwningAttache(User $user, Inquiry $inquiry): bool
    {
        return $user->role?->name === 'Ministry Attache' && $user->mission_id === $inquiry->mission_id;
    }
}
