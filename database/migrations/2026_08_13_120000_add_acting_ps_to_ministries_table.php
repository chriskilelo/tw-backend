<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ministries', function (Blueprint $table) {
            $table->foreignUuid('acting_ps_user_id')->nullable()->after('designated_deputy_active')->constrained('users')->nullOnDelete();
            $table->boolean('acting_ps_active')->default(false)->after('acting_ps_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ministries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acting_ps_user_id');
            $table->dropColumn('acting_ps_active');
        });
    }
};
