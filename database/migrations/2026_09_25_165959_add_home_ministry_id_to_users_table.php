<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006: a System Administrator may be affiliated with a home department
 * (e.g. SDT) for display purposes only. Deliberately not users.ministry_id:
 * App\Models\Scopes\MinistryScope falls back to Auth::user()->ministry_id
 * on routes without the ministry.scope middleware, so reusing that column
 * would silently confine a platform-wide administrator to one department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('home_ministry_id')->nullable()->after('ministry_id')->constrained('ministries');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_ministry_id');
        });
    }
};
