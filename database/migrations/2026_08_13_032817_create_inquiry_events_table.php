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
        Schema::create('inquiry_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('inquiry_id')->constrained('inquiries');
            $table->string('event_type', 50);
            $table->text('note')->nullable();
            $table->foreignUuid('logged_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['inquiry_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiry_events');
    }
};
