<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferralOrganisationSeeder extends Seeder
{
    /**
     * The 7 initial referral organisations (CLAUDE.md Section 8, Referral
     * Register Engine Configuration).
     *
     * @var array<int, string>
     */
    protected array $organisations = [
        'Export Promotion and Branding Agency (EPBA)',
        'Kenya Investment Authority (KenInvest)',
        'Kenya Trade Network Agency (KenTrade)',
        'Kenya National Bureau of Statistics (KNBS)',
        'Ministry of Agriculture',
        'Ministry of Tourism',
        'Kenya National Chamber of Commerce and Industry (KNCCI)',
    ];

    /**
     * Seed the initial referral organisation registry.
     */
    public function run(): void
    {
        $ministryId = DB::table('ministries')
            ->where('name', 'State Department for Trade')
            ->value('id');

        if (! $ministryId) {
            throw new \RuntimeException('SDT ministry not found; run MinistrySeeder first.');
        }

        foreach ($this->organisations as $name) {
            $exists = DB::table('referral_organisations')
                ->where('ministry_id', $ministryId)
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('referral_organisations')->insert([
                'ministry_id' => $ministryId,
                'name' => $name,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
