<?php

namespace App\Policies;

use App\Models\User;

/**
 * FR-AUDIT-005: the audit trail is System Administrator only. Platform-wide,
 * never ministry-scoped, since audit_logs carries no ministry_id
 * (CLAUDE.md Section 6).
 */
class AuditLogPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role?->name === 'System Administrator';
    }
}
