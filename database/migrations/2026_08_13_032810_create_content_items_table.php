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
        Schema::create('content_items', function (Blueprint $table) {
            // Primary key is added as an explicit early command (rather than the fluent
            // ->primary() column modifier, which Laravel defers until after all columns
            // are defined) so it exists before the self-referencing
            // public_version_of_content_item_id foreign key constraint below is compiled.
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->primary('id');
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->string('title', 255);
            $table->string('category', 100)->nullable();
            $table->string('classification_level', 30)->default('internal_use_only');
            $table->text('body')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_comment')->nullable();
            $table->boolean('publication_ready')->default(false);
            $table->foreignUuid('public_version_of_content_item_id')->nullable()->constrained('content_items');
            $table->timestamps();

            $table->index(['ministry_id', 'status']);
            $table->index('classification_level');
            $table->index('publication_ready');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
