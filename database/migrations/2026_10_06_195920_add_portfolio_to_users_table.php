<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-SDT-012: the configured portfolio of a Ministry HQ Officer, the
 * missions, countries and sectors their workspace defaults to. Shape:
 * {"mission_ids": [uuid], "countries": [string], "sectors": [string]};
 * null when nothing is configured (App\Support\HqPortfolio). A view
 * default, not an access boundary: the officer may still widen the
 * workspace to the whole department (FR-AUTH-015).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('portfolio')->nullable()->after('email_notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('portfolio');
        });
    }
};
