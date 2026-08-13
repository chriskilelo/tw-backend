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
        Schema::create('alert_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('alert_id')->constrained('alerts');
            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->bigInteger('file_size_bytes');
            $table->string('mime_type', 100);
            $table->foreignUuid('uploaded_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('alert_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_attachments');
    }
};
