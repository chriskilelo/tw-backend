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
            $table->foreignUuid('designated_deputy_user_id')->nullable()->after('active')->constrained('users')->nullOnDelete();
            $table->boolean('designated_deputy_active')->default(false)->after('designated_deputy_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ministries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('designated_deputy_user_id');
            $table->dropColumn('designated_deputy_active');
        });
    }
};
