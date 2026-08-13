<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MissionSeeder extends Seeder
{
    /**
     * The 17-mission roster (CLAUDE.md Section 8, Mission Roster).
     *
     * host_country and time_zone are not enumerated in CLAUDE.md itself;
     * they are filled in here as objective geography (host country of the
     * mission city, IANA time zone identifier), not invented business data.
     *
     * @var array<int, array{name: string, city: string, host_country: string, time_zone: string}>
     */
    protected array $missions = [
        ['name' => 'Washington D.C.', 'city' => 'Washington', 'host_country' => 'United States', 'time_zone' => 'America/New_York'],
        ['name' => 'New Delhi', 'city' => 'New Delhi', 'host_country' => 'India', 'time_zone' => 'Asia/Kolkata'],
        ['name' => 'Kinshasa', 'city' => 'Kinshasa', 'host_country' => 'Democratic Republic of the Congo', 'time_zone' => 'Africa/Kinshasa'],
        ['name' => 'Arusha', 'city' => 'Arusha', 'host_country' => 'Tanzania', 'time_zone' => 'Africa/Dar_es_Salaam'],
        ['name' => 'London', 'city' => 'London', 'host_country' => 'United Kingdom', 'time_zone' => 'Europe/London'],
        ['name' => 'Lusaka', 'city' => 'Lusaka', 'host_country' => 'Zambia', 'time_zone' => 'Africa/Lusaka'],
        ['name' => 'Berlin', 'city' => 'Berlin', 'host_country' => 'Germany', 'time_zone' => 'Europe/Berlin'],
        ['name' => 'Pretoria', 'city' => 'Pretoria', 'host_country' => 'South Africa', 'time_zone' => 'Africa/Johannesburg'],
        ['name' => 'Accra', 'city' => 'Accra', 'host_country' => 'Ghana', 'time_zone' => 'Africa/Accra'],
        ['name' => 'Cairo', 'city' => 'Cairo', 'host_country' => 'Egypt', 'time_zone' => 'Africa/Cairo'],
        ['name' => 'Jakarta', 'city' => 'Jakarta', 'host_country' => 'Indonesia', 'time_zone' => 'Asia/Jakarta'],
        ['name' => 'Beijing', 'city' => 'Beijing', 'host_country' => 'China', 'time_zone' => 'Asia/Shanghai'],
        ['name' => 'Dubai', 'city' => 'Dubai', 'host_country' => 'United Arab Emirates', 'time_zone' => 'Asia/Dubai'],
        ['name' => 'Islamabad', 'city' => 'Islamabad', 'host_country' => 'Pakistan', 'time_zone' => 'Asia/Karachi'],
        ['name' => 'Brasilia', 'city' => 'Brasilia', 'host_country' => 'Brazil', 'time_zone' => 'America/Sao_Paulo'],
        ['name' => 'Kampala', 'city' => 'Kampala', 'host_country' => 'Uganda', 'time_zone' => 'Africa/Kampala'],
        ['name' => 'Addis Ababa', 'city' => 'Addis Ababa', 'host_country' => 'Ethiopia', 'time_zone' => 'Africa/Addis_Ababa'],
    ];

    /**
     * Seed the 17 missions with active Commercial Attaches.
     */
    public function run(): void
    {
        foreach ($this->missions as $mission) {
            $existing = DB::table('missions')->where('name', $mission['name'])->first();

            if ($existing) {
                DB::table('missions')->where('id', $existing->id)->update([
                    'city' => $mission['city'],
                    'host_country' => $mission['host_country'],
                    'time_zone' => $mission['time_zone'],
                    'active' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('missions')->insert([
                'name' => $mission['name'],
                'city' => $mission['city'],
                'host_country' => $mission['host_country'],
                'time_zone' => $mission['time_zone'],
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
