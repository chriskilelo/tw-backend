<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MissionMinistryLinkSeeder extends Seeder
{
    /**
     * Link every mission to the SDT ministry (BR-004: one active attache
     * per mission per ministry, enforced by the unique index on this
     * table). Attaches are provisioned in a separate user-provisioning
     * session, so active_attache_user_id is left null here.
     */
    public function run(): void
    {
        $ministryId = DB::table('ministries')
            ->where('name', 'State Department for Trade')
            ->value('id');

        if (! $ministryId) {
            throw new \RuntimeException('SDT ministry not found; run MinistrySeeder first.');
        }

        $missionIds = DB::table('missions')->pluck('id');

        foreach ($missionIds as $missionId) {
            $exists = DB::table('mission_ministry_links')
                ->where('mission_id', $missionId)
                ->where('ministry_id', $ministryId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('mission_ministry_links')->insert([
                'mission_id' => $missionId,
                'ministry_id' => $ministryId,
                'active_attache_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
