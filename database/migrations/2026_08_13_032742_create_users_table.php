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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('full_name', 255);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->foreignUuid('role_id')->constrained('roles');
            $table->foreignUuid('mission_id')->nullable()->constrained('missions');
            $table->foreignUuid('ministry_id')->nullable()->constrained('ministries');
            $table->string('status', 30)->default('activation_pending');
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->string('language_preference', 5)->default('en');
            $table->jsonb('email_notification_preferences')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index('role_id');
            $table->index('mission_id');
            $table->index('ministry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
