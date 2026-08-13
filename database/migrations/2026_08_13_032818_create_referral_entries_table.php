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
        Schema::create('referral_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('inquiry_id')->constrained('inquiries');
            $table->foreignUuid('referral_organisation_id')->constrained('referral_organisations');
            $table->string('contact_person', 255)->nullable();
            $table->date('referral_date');
            $table->string('referral_method', 100)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index('inquiry_id');
            $table->index('referral_organisation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_entries');
    }
};
