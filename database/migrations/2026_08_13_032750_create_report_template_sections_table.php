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
        Schema::create('report_template_sections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->integer('version');
            $table->date('effective_date');
            $table->integer('section_order');
            $table->string('section_title', 255);
            $table->string('section_type', 20);
            $table->jsonb('column_schema')->nullable();
            $table->text('guidance_text')->nullable();
            $table->timestamps();

            $table->index(['ministry_id', 'version', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_template_sections');
    }
};
