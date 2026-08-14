<?php

namespace Database\Seeders;

use App\Enums\LanguagePreference;
use App\Enums\UserStatus;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Session 37 (k6 load testing, NFR-PERF-001/002): seeds a fixed pool of 50
 * throwaway Ministry Attache accounts, round-robin assigned across all 17
 * missions, so the k6 scripts under tests/load/ have enough distinct
 * authenticated sessions to simulate concurrency without reusing the
 * QaTestAccountSeeder fixtures (which the Playwright suites also depend on).
 * Because the round-robin assigns missions in seed order, the first 17
 * accounts already cover every mission exactly once — tests/load/report-form.js
 * relies on that to get "one attache per mission" without a second seeder.
 *
 * Deliberately not called from DatabaseSeeder::run(), same convention as
 * QaTestAccountSeeder — invoked explicitly via
 * `php artisan db:seed --class=LoadTestAccountSeeder` before running k6.
 * Idempotent (check-then-upsert on natural key).
 */
class LoadTestAccountSeeder extends Seeder
{
    private const int ACCOUNT_COUNT = 50;

    public function run(): void
    {
        $sdt = Ministry::query()->where('name', 'State Department for Trade')->firstOrFail();
        $role = Role::query()->where('name', 'Ministry Attache')->firstOrFail();
        $missions = Mission::query()->orderBy('name')->get();

        $password = env('TW_TEST_DEFAULT_PW', 'QaTest!2026Pw');

        for ($i = 0; $i < self::ACCOUNT_COUNT; $i++) {
            $mission = $missions[$i % $missions->count()];
            $email = sprintf('loadtest.user%02d@tradewatch.go.ke', $i);

            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'full_name' => 'Load Test User '.Str::padLeft((string) $i, 2, '0'),
                    'password' => Hash::make($password),
                    'role_id' => $role->id,
                    'ministry_id' => $sdt->id,
                    'mission_id' => $mission->id,
                    'status' => UserStatus::Active,
                    'failed_login_attempts' => 0,
                    'language_preference' => LanguagePreference::English,
                ],
            );
        }
    }
}
