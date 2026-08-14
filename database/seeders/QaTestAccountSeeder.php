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

/**
 * Session 24 (Stage 1 Gate): seeds the fixed set of test accounts the
 * 20_TW_Playwright_Test_Automation suites authenticate as. Never run against
 * a real deployment — these are throwaway QA fixtures, not real SDT
 * personnel (CLAUDE.md Section 8's real mission roster is untouched).
 *
 * Deliberately not called from DatabaseSeeder::run() — invoked explicitly via
 * `php artisan db:seed --class=QaTestAccountSeeder` only when standing up a
 * local/Staging environment for E2E runs. Idempotent, same convention as
 * every other seeder in this directory (check-then-upsert on natural key).
 */
class QaTestAccountSeeder extends Seeder
{
    public function run(): void
    {
        $sdt = Ministry::query()->where('name', 'State Department for Trade')->firstOrFail();
        $london = Mission::query()->where('name', 'London')->firstOrFail();
        $berlin = Mission::query()->where('name', 'Berlin')->firstOrFail();

        $synthetic = Ministry::query()->firstOrCreate(
            ['name' => 'QA Synthetic Ministry'],
            ['active' => true],
        );

        $password = env('TW_TEST_DEFAULT_PW', 'QaTest!2026Pw');

        $this->account('attache.london@tradewatch.go.ke', 'QA Attache London', 'Ministry Attache', $sdt->id, $london->id, $password);
        $this->account('ps@tradewatch.go.ke', 'QA Ministry PS', 'Ministry PS', $sdt->id, null, $password);
        $this->account('hom@tradewatch.go.ke', 'QA Head of Mission', 'Head of Mission', $sdt->id, $london->id, $password);
        $this->account('dhom@tradewatch.go.ke', 'QA Deputy Head of Mission', 'Deputy Head of Mission', $sdt->id, $london->id, $password);
        $this->account('mfahq@tradewatch.go.ke', 'QA MFA HQ Officer', 'MFA HQ Officer', null, null, $password);
        $this->account('mfaps@tradewatch.go.ke', 'QA MFA Principal Secretary', 'MFA Principal Secretary', null, null, $password);
        $this->account('sysadmin@tradewatch.go.ke', 'QA System Administrator', 'System Administrator', null, null, $password);
        $this->account('synthetic.attache@tradewatch.go.ke', 'QA Synthetic Attache', 'Ministry Attache', $synthetic->id, $berlin->id, $password);
        $this->account('director@tradewatch.go.ke', 'QA Director External Trade', 'Ministry HQ Officer', $sdt->id, null, $password);
        $this->account('hqdirector@tradewatch.go.ke', 'QA Ministry HQ Director', 'Ministry HQ Director', $sdt->id, null, $password);
    }

    private function account(string $email, string $fullName, string $roleName, ?string $ministryId, ?string $missionId, string $password): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'full_name' => $fullName,
                'password' => Hash::make($password),
                'role_id' => $role->id,
                'ministry_id' => $ministryId,
                'mission_id' => $missionId,
                'status' => UserStatus::Active,
                'failed_login_attempts' => 0,
                'language_preference' => LanguagePreference::English,
            ],
        );
    }
}
