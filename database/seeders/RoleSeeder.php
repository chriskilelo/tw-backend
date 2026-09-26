<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * The 15-role catalogue (TW-ARCH-001 Section 8.1 / CLAUDE.md Section 5),
     * including the Ministry Administrator added by ADR-006.
     *
     * Layer values are stored verbatim from the authoritative source table,
     * including the dual-layer "2/3" roles (Ministry PS, Ministry Publishing
     * Authority, Designated Deputy, Acting PS), rather than collapsed to a
     * single digit.
     *
     * display_title (FR-AUTH-025) is cosmetic only and never read by an
     * authorisation check; it is written only when the row has none yet, so
     * a title later changed directly in the data survives a re-seed.
     *
     * @var array<int, array{name: string, layer: string, scope: string, display_title?: string}>
     */
    protected array $roles = [
        ['name' => 'System Administrator', 'layer' => '1', 'scope' => 'platform', 'display_title' => 'High Warden'],
        ['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission'],
        ['name' => 'Deputy Head of Mission', 'layer' => '1', 'scope' => 'mission'],
        ['name' => 'MFA HQ Officer', 'layer' => '1', 'scope' => 'platform'],
        ['name' => 'MFA Principal Secretary', 'layer' => '1', 'scope' => 'platform'],
        ['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission'],
        ['name' => 'Ministry HQ Officer', 'layer' => '2', 'scope' => 'ministry'],
        ['name' => 'Ministry HQ Director', 'layer' => '2', 'scope' => 'ministry'],
        ['name' => 'Ministry PS', 'layer' => '2/3', 'scope' => 'ministry'],
        ['name' => 'Ministry Publishing Authority', 'layer' => '2/3', 'scope' => 'ministry'],
        ['name' => 'HRM&D Officer', 'layer' => '3', 'scope' => 'ministry'],
        ['name' => 'Designated Deputy', 'layer' => '2/3', 'scope' => 'ministry'],
        ['name' => 'Acting PS', 'layer' => '2/3', 'scope' => 'ministry'],
        ['name' => 'Honorary Consul', 'layer' => '2', 'scope' => 'mission'],
        ['name' => 'Ministry Administrator', 'layer' => '1', 'scope' => 'ministry', 'display_title' => 'Warden'],
    ];

    /**
     * Seed the 15 platform roles.
     */
    public function run(): void
    {
        foreach ($this->roles as $role) {
            $existing = DB::table('roles')->where('name', $role['name'])->first();

            if ($existing) {
                DB::table('roles')->where('id', $existing->id)->update([
                    'layer' => $role['layer'],
                    'scope' => $role['scope'],
                    'display_title' => $existing->display_title ?? $role['display_title'] ?? null,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('roles')->insert([
                'name' => $role['name'],
                'layer' => $role['layer'],
                'scope' => $role['scope'],
                'display_title' => $role['display_title'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
