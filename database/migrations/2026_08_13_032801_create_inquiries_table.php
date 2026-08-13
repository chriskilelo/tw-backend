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
        Schema::create('inquiries', function (Blueprint $table) {
            // Primary key is added as an explicit early command (rather than the fluent
            // ->primary() column modifier, which Laravel defers until after all columns
            // are defined) so it exists before the self-referencing linked_inquiry_id
            // foreign key constraint below is compiled.
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->primary('id');
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('logged_by_user_id')->constrained('users');
            $table->string('reference_number', 50)->unique();
            $table->string('category', 100);
            $table->string('sub_type', 30)->default('standard');
            $table->string('inquirer_name', 255);
            $table->string('inquirer_organisation', 255)->nullable();
            $table->string('inquirer_email', 255)->nullable();
            $table->string('inquirer_phone', 50)->nullable();
            $table->string('product_or_sector', 150)->nullable();
            $table->text('description')->nullable();
            $table->date('date_received');
            $table->string('status', 30)->default('draft');
            $table->boolean('high_value_flag')->default(false);
            $table->text('high_value_justification')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('linked_inquiry_id')->nullable()->constrained('inquiries');
            $table->timestamps();

            $table->index(['ministry_id', 'mission_id']);
            $table->index('status');
            $table->index('category');
            $table->index('high_value_flag');
            $table->index('linked_inquiry_id');
        });

        // tsvector generated column: Blueprint has no native tsvector type, so it is
        // added via raw DDL. Generated (STORED) from columns within this same table only.
        DB::statement(<<<'SQL'
            ALTER TABLE inquiries ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(inquirer_name, '') || ' ' ||
                    coalesce(description, '') || ' ' ||
                    coalesce(product_or_sector, '')
                )
            ) STORED
        SQL);
        DB::statement('CREATE INDEX inquiries_search_vector_index ON inquiries USING GIN (search_vector)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
