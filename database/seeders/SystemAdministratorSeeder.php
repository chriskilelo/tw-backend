<?php

namespace Database\Seeders;

use App\Enums\LanguagePreference;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates (or resets) the platform's named System Administrator account,
 * so a fresh environment no longer needs a hand-run tinker command to get
 * its first administrator (CLAUDE.md Section 18, item 5).
 *
 * Not called from DatabaseSeeder::run(), matching the other account
 * seeders; run it explicitly with
 * `php artisan db:seed --class=SystemAdministratorSeeder`. Every run sets
 * the password back to TW_ADMIN_DEFAULT_PW (or the fallback below) and
 * clears any lock, so it doubles as a password reset for this account.
 */
class SystemAdministratorSeeder extends Seeder
{
    private const string EMAIL = 'chris.kilelo@icta.go.ke';

    private const string FULL_NAME = 'Chris Kilelo';

    private const string DEFAULT_PASSWORD_ENV = 'TW_ADMIN_DEFAULT_PW';

    private const string DEFAULT_PASSWORD_FALLBACK = 'TradeWatch!2026Demo';

    public function run(): void
    {
        $roleId = Role::query()->where('name', 'System Administrator')->valueOrFail('id');

        $administrator = User::withTrashed()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'full_name' => self::FULL_NAME,
                'password' => Hash::make(env(self::DEFAULT_PASSWORD_ENV, self::DEFAULT_PASSWORD_FALLBACK)),
                'role_id' => $roleId,
                'ministry_id' => null,
                'mission_id' => null,
                'status' => UserStatus::Active,
                'failed_login_attempts' => 0,
                'language_preference' => LanguagePreference::English,
            ],
        );

        if ($administrator->trashed()) {
            $administrator->restore();
        }
    }
}
