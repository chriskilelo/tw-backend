<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\BasePolicy;

/**
 * CLAUDE.md Section 11 / FR-AUDIT-001 to 005: writes the single, immutable
 * audit trail every mutation and authentication event must produce. Never
 * bypassed; audit_logs has no updated_at/deleted_at and App\Models\AuditLog
 * is append-only (CLAUDE.md Section 4, Rule 3).
 */
class AuditService
{
    /**
     * $ministryId (FR-AUDIT-006) is the department the entry concerns; when
     * omitted it falls back to the acting user's own department, which is
     * null for platform-scoped actors such as a System Administrator.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public function record(
        ?User $actor,
        string $action,
        string $affectedEntityType,
        ?string $affectedEntityId = null,
        ?array $changes = null,
        ?string $ipAddress = null,
        ?string $ministryId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'ministry_id' => $ministryId ?? $actor?->ministry_id,
            'action' => $action,
            'affected_entity_type' => $affectedEntityType,
            'affected_entity_id' => $affectedEntityId,
            'changes' => $changes,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * DPIA Section 6 ("Expanded access surface from Head of Mission and MFA
     * visibility"): access by the mission-governance roles is logged. The
     * only content those roles can open is their own mission's records
     * (FR-HOM-001 AC2), so each time a Head or Deputy Head of Mission opens
     * a record or one of its attachments, it is recorded against the
     * record's department. A no-op for every other role, whose reads are
     * not audited (FR-AUDIT-001).
     */
    public function recordOversightAccess(?User $viewer, string $affectedEntityType, string $affectedEntityId, ?string $ministryId, ?string $ipAddress = null): void
    {
        if (! in_array($viewer?->role?->name, BasePolicy::MISSION_OVERSIGHT_ROLES, true)) {
            return;
        }

        $this->record($viewer, 'mission_oversight.record_accessed', $affectedEntityType, $affectedEntityId, null, $ipAddress, $ministryId);
    }
}
