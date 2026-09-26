<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-AUTH-022/023, BR-027: Principal Secretary appointment, promotion,
 * deactivation and succession requests raised by a Ministry Administrator
 * and decided by a System Administrator.
 *
 * A requested new PS account is held in `payload` and only created on
 * approval — never as a pending users row, which could otherwise be
 * self-activated through the password-reset flow.
 *
 * The partial unique index allows at most one pending request per
 * department at a time, so two conflicting PS changes can never both be
 * awaiting a decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->string('type', 30);
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->foreignUuid('subject_user_id')->nullable()->constrained('users');
            $table->jsonb('payload')->nullable();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['ministry_id', 'status']);
            $table->index('status');
        });

        DB::statement("CREATE UNIQUE INDEX approval_requests_one_pending_per_ministry ON approval_requests (ministry_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
