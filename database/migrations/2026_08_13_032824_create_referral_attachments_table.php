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
        Schema::create('referral_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('referral_entry_id')->constrained('referral_entries');
            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->bigInteger('file_size_bytes');
            $table->string('mime_type', 100);
            $table->foreignUuid('uploaded_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('referral_entry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_attachments');
    }
};
