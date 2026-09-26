<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-AUTH-025: a cosmetic, data-driven title shown for a role under the
 * user's profile picture and in email sign-offs. Never read by any
 * authorisation check — roles.name remains the only value policies test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_title', 100)->nullable()->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('display_title');
        });
    }
};
