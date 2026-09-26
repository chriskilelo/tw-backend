<?php

namespace Database\Seeders;

use App\Enums\LanguagePreference;
use App\Enums\UserStatus;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Support\DemoManifest;
use Database\Seeders\Support\EmailSlug;
use Database\Seeders\Support\MissionTradeProfiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the real-named attache roster (17 primary attaches, matching
 * CLAUDE.md Section 8's existing canon list, plus junior attaches at the
 * 5 higher-volume missions) and 7 named SDT HQ personnel, then points each
 * mission's mission_ministry_links.active_attache_user_id at its primary
 * attache. Every user is idempotent (updateOrCreate by email) so re-running
 * `php artisan db:seed --class=DemoDataSeeder` after a clear is safe.
 */
class DemoRosterSeeder extends Seeder
{
    private const string DEFAULT_PASSWORD_ENV = 'TW_DEMO_DEFAULT_PW';

    private const string DEFAULT_PASSWORD_FALLBACK = 'TradeWatch!2026Demo';

    /**
     * @return array{
     *     primary: array<string, User>,
     *     junior: array<string, array<int, User>>,
     *     hq: array<string, User>,
     *     ministry_administrators: array<int, User>,
     * }
     */
    public function seed(DemoManifest $manifest, string $ministryId): array
    {
        $password = env(self::DEFAULT_PASSWORD_ENV, self::DEFAULT_PASSWORD_FALLBACK);
        $attacheRoleId = Role::query()->where('name', 'Ministry Attache')->value('id');

        $primary = [];
        $junior = [];

        foreach (MissionTradeProfiles::all() as $missionName => $profile) {
            $mission = Mission::query()->where('name', $missionName)->firstOrFail();

            $primaryUser = $this->upsertUser(
                $manifest,
                $profile['primary_attache'],
                $attacheRoleId,
                $ministryId,
                $mission->id,
                $password,
            );
            $primary[$missionName] = $primaryUser;

            $junior[$missionName] = [];

            foreach ($profile['junior_attaches'] as $juniorName) {
                $junior[$missionName][] = $this->upsertUser(
                    $manifest,
                    $juniorName,
                    $attacheRoleId,
                    $ministryId,
                    $mission->id,
                    $password,
                );
            }

            $link = MissionMinistryLink::query()
                ->where('mission_id', $mission->id)
                ->where('ministry_id', $ministryId)
                ->first();

            if ($link !== null) {
                $manifest->recordMissionLinkReset($mission->id, $link->active_attache_user_id);
                $link->forceFill(['active_attache_user_id' => $primaryUser->id])->save();
            }
        }

        $hq = $this->seedHqPersonnel($manifest, $ministryId, $password);
        $ministryAdministrators = $this->seedMinistryAdministrators($manifest, $ministryId, $password);

        return ['primary' => $primary, 'junior' => $junior, 'hq' => $hq, 'ministry_administrators' => $ministryAdministrators];
    }

    /**
     * BR-028: at most 3 active Ministry Administrators per department. This
     * dataset seeds exactly the cap for SDT — a Ministry Administrator has
     * no operational access at all (BR-025), so unlike every other role in
     * this roster, none of these three are ever referenced by the
     * report/alert/inquiry/directive/KPI seeders below.
     *
     * @return array<int, User>
     */
    private function seedMinistryAdministrators(DemoManifest $manifest, string $ministryId, string $password): array
    {
        $roleId = Role::query()->where('name', 'Ministry Administrator')->value('id');

        $names = ['Patrice Mutua', 'Grace Nyambura', 'Samuel Njoroge'];

        return array_map(
            fn (string $name): User => $this->upsertUser($manifest, $name, $roleId, $ministryId, null, $password),
            $names,
        );
    }

    /**
     * @return array<string, User>
     */
    private function seedHqPersonnel(DemoManifest $manifest, string $ministryId, string $password): array
    {
        $roleId = fn (string $roleName): string => Role::query()->where('name', $roleName)->value('id');

        $people = [
            'ps' => ['name' => 'Dr. Susan Kiragu', 'role' => 'Ministry PS'],
            'publishing_authority' => ['name' => 'Esther Kariuki', 'role' => 'Ministry Publishing Authority'],
            'hq_director' => ['name' => 'James Mutuku', 'role' => 'Ministry HQ Director'],
            'hq_officer_1' => ['name' => 'Faith Chebet', 'role' => 'Ministry HQ Officer'],
            'hq_officer_2' => ['name' => 'Dennis Omondi', 'role' => 'Ministry HQ Officer'],
            'hrmd_officer' => ['name' => 'Lucy Wambui', 'role' => 'HRM&D Officer'],
            'designated_deputy' => ['name' => 'Michael Otiende', 'role' => 'Designated Deputy'],
        ];

        $hq = [];

        foreach ($people as $key => $person) {
            $hq[$key] = $this->upsertUser(
                $manifest,
                $person['name'],
                $roleId($person['role']),
                $ministryId,
                null,
                $password,
            );
        }

        return $hq;
    }

    private function upsertUser(
        DemoManifest $manifest,
        string $fullName,
        string $roleId,
        string $ministryId,
        ?string $missionId,
        string $password,
    ): User {
        $email = EmailSlug::for($fullName);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'full_name' => $fullName,
                'password' => Hash::make($password),
                'role_id' => $roleId,
                'ministry_id' => $ministryId,
                'mission_id' => $missionId,
                'status' => UserStatus::Active,
                'failed_login_attempts' => 0,
                'language_preference' => LanguagePreference::English,
            ],
        );

        $manifest->add('users', $user->id);

        return $user;
    }
}
