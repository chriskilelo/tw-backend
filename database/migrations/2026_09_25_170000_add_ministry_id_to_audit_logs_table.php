<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-AUDIT-006: a Ministry Administrator sees only its own department's
 * administrative audit entries, so each row records the department it
 * concerns. Nullable: platform-level events (e.g. mission registry changes)
 * belong to no single department.
 *
 * The backfill uses raw SQL because App\Models\AuditLog is append-only at
 * the model level (CLAUDE.md Section 4, Rule 3). It needs UPDATE on
 * audit_logs, so it must run before database/security/revoke_permissions.sql
 * is (re-)applied in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignUuid('ministry_id')->nullable()->after('user_id')->constrained('ministries');
            $table->index(['ministry_id', 'created_at']);
        });

        // Rows about a user belong to that user's department.
        DB::statement(<<<'SQL'
            UPDATE audit_logs
            SET ministry_id = users.ministry_id
            FROM users
            WHERE audit_logs.ministry_id IS NULL
              AND audit_logs.affected_entity_type = 'App\Models\User'
              AND audit_logs.affected_entity_id = users.id
              AND users.ministry_id IS NOT NULL
        SQL);

        // Ministry-level events (Acting PS switches) name the department directly.
        DB::statement(<<<'SQL'
            UPDATE audit_logs
            SET ministry_id = audit_logs.affected_entity_id
            WHERE audit_logs.ministry_id IS NULL
              AND audit_logs.affected_entity_type = 'ministry'
              AND EXISTS (SELECT 1 FROM ministries WHERE ministries.id = audit_logs.affected_entity_id)
        SQL);

        // Everything else falls back to the acting user's department.
        DB::statement(<<<'SQL'
            UPDATE audit_logs
            SET ministry_id = users.ministry_id
            FROM users
            WHERE audit_logs.ministry_id IS NULL
              AND audit_logs.user_id = users.id
              AND users.ministry_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['ministry_id', 'created_at']);
            $table->dropConstrainedForeignId('ministry_id');
        });
    }
};
