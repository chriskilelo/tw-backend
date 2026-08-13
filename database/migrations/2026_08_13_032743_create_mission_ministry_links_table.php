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
        Schema::create('mission_ministry_links', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->foreignUuid('active_attache_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['mission_id', 'ministry_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mission_ministry_links');
    }
};
