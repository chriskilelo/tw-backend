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
        Schema::create('master_data_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('ministry_id')->nullable()->constrained('ministries');
            $table->string('category', 100);
            $table->string('value', 255);
            $table->integer('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['ministry_id', 'category', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_data_entries');
    }
};
