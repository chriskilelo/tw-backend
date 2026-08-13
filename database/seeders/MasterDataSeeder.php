<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataSeeder extends Seeder
{
    /**
     * Configurable reference lists for SDT (CLAUDE.md Section 8).
     *
     * Two categories referenced by the session brief are deliberately not
     * seeded here: 'directive_type' and 'content_category'. CLAUDE.md
     * Section 8 states directive type has "no fixed category list
     * configured at go-live" and content categories are "pending SDT
     * confirmation" (Section 16, ISSUE items). Seeding placeholder values
     * for either would violate the "do not invent values" instruction.
     *
     * @var array<string, array<int, string>>
     */
    protected array $entries = [
        'inquiry_category' => [
            'Buyer Seeking Supplier',
            'Investor Seeking Partner',
            'General Market Question',
            'Investor Seeking Buyer',
            'Investor Seeking Investment Opportunities',
            'Disputes/Complaints',
        ],
        'alert_intelligence_type' => [
            'opportunities',
            'trade_barriers',
        ],
        'inquiry_workflow_status' => [
            'draft',
            'received',
            'in_progress',
            'pending_external_response',
            'resolved',
            'closed',
            'cancelled',
        ],
        'inquiry_event_type' => [
            'hq_notified',
            'referral_made',
            'feedback_received',
            'reminder_sent',
            'follow_up_completed',
        ],
    ];

    /**
     * Seed SDT's configurable master data entries.
     */
    public function run(): void
    {
        $ministryId = DB::table('ministries')
            ->where('name', 'State Department for Trade')
            ->value('id');

        if (! $ministryId) {
            throw new \RuntimeException('SDT ministry not found; run MinistrySeeder first.');
        }

        foreach ($this->entries as $category => $values) {
            foreach ($values as $index => $value) {
                $exists = DB::table('master_data_entries')
                    ->where('ministry_id', $ministryId)
                    ->where('category', $category)
                    ->where('value', $value)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('master_data_entries')->insert([
                    'ministry_id' => $ministryId,
                    'category' => $category,
                    'value' => $value,
                    'display_order' => $index + 1,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
