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
        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('submitted_by_user_id')->constrained('users');
            $table->string('reference_number', 50)->unique();
            $table->string('country', 100);
            $table->string('sector', 100)->nullable();
            $table->string('product_category', 150)->nullable();
            $table->text('product_description')->nullable();
            $table->string('intelligence_type', 50);
            $table->string('intelligence_source', 255)->nullable();
            $table->string('urgency', 50)->nullable();
            $table->string('confidence_rating', 50)->nullable();
            $table->jsonb('tags')->nullable();
            $table->string('status', 30)->default('new');
            $table->foreignUuid('assigned_to_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['ministry_id', 'mission_id']);
            $table->index('status');
            $table->index('assigned_to_user_id');
        });

        // tsvector generated column: Blueprint has no native tsvector type, so it is
        // added via raw DDL. Generated (STORED) from columns within this same table only.
        DB::statement(<<<'SQL'
            ALTER TABLE alerts ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(country, '') || ' ' ||
                    coalesce(sector, '') || ' ' ||
                    coalesce(product_description, '') || ' ' ||
                    coalesce(intelligence_source, '')
                )
            ) STORED
        SQL);
        DB::statement('CREATE INDEX alerts_search_vector_index ON alerts USING GIN (search_vector)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
