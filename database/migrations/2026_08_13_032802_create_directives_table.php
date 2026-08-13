<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('directives', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('target_user_id')->constrained('users');
            $table->foreignUuid('issued_by_user_id')->constrained('users');
            $table->string('type_category', 100)->nullable();
            $table->text('description');
            $table->date('target_completion_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('completion_summary')->nullable();
            $table->timestamp('last_progress_update_at')->nullable();
            $table->timestamps();

            $table->index(['ministry_id', 'mission_id']);
            $table->index('target_user_id');
            $table->index('status');
            $table->index('target_completion_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('directives');
    }
};
