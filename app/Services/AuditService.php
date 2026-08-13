<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

/**
 * CLAUDE.md Section 11 / FR-AUDIT-001 to 005: writes the single, immutable
 * audit trail every mutation and authentication event must produce. Never
 * bypassed; audit_logs has no updated_at/deleted_at and App\Models\AuditLog
 * is append-only (CLAUDE.md Section 4, Rule 3).
 */
class AuditService
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function record(
        ?User $actor,
        string $action,
        string $affectedEntityType,
        ?string $affectedEntityId = null,
        ?array $changes = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'affected_entity_type' => $affectedEntityType,
            'affected_entity_id' => $affectedEntityId,
            'changes' => $changes,
            'ip_address' => $ipAddress,
        ]);
    }
}
