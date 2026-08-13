<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MinistrySeeder extends Seeder
{
    /**
     * Seed the single SDT ministry record (CLAUDE.md Section 8).
     */
    public function run(): void
    {
        $existing = DB::table('ministries')->where('name', 'State Department for Trade')->first();

        if ($existing) {
            DB::table('ministries')->where('id', $existing->id)->update([
                'active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('ministries')->insert([
            'name' => 'State Department for Trade',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
