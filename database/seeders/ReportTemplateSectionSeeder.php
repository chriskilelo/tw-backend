<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReportTemplateSectionSeeder extends Seeder
{
    protected const VERSION = 1;

    /**
     * The 12-section SDT quarterly report template, version 1
     * (CLAUDE.md Section 8, Report Template Structure).
     *
     * column_schema for the two structured_table sections mirrors the
     * Asset Register and AIE Allocations schemas in CLAUDE.md Section 8;
     * guidance_text is taken verbatim from that section's "Notes" column.
     *
     * @var array<int, array{title: string, type: string, guidance: ?string, schema: ?array}>
     */
    protected array $sections = [
        ['title' => 'Introduction', 'type' => 'narrative', 'guidance' => 'Outline, background, objectives, limitations/assumptions', 'schema' => null],
        ['title' => 'Trade and Investment Inquiries and Forums Attended', 'type' => 'narrative', 'guidance' => 'Trade shows, forums, inquiries responded to, meetings, disputes resolved, visits', 'schema' => null],
        ['title' => 'Trade Performance Trends (Kenya vs. Accredited Countries)', 'type' => 'narrative', 'guidance' => 'Top 10 exports/imports, balance of trade, trade value/volume, cooperation areas', 'schema' => null],
        ['title' => 'Global Trade Performance Trends of Accredited Countries', 'type' => 'narrative', 'guidance' => 'Top 10 imports/exports, top destinations, potential Kenyan exports', 'schema' => null],
        ['title' => 'Market Intelligence Survey on Kenyan Products', 'type' => 'narrative', 'guidance' => 'Spot visits, market access patterns, exporter challenges', 'schema' => null],
        ['title' => 'Trade Agreements of Accredited Countries', 'type' => 'narrative', 'guidance' => 'Bilateral/regional agreements, WTO-contravening policies, mitigation', 'schema' => null],
        ['title' => 'Multilateral Trade Engagements', 'type' => 'narrative', 'guidance' => 'Meetings attended, issues discussed, outcomes for Kenya', 'schema' => null],
        [
            'title' => 'Asset Register of Trade Foreign Mission Office',
            'type' => 'structured_table',
            'guidance' => null,
            'schema' => [
                ['name' => 'Item Number', 'type' => 'integer', 'mandatory' => true],
                ['name' => 'Item Description', 'type' => 'text', 'mandatory' => true],
                ['name' => 'Serial Number', 'type' => 'text', 'mandatory' => false],
                ['name' => 'Status', 'type' => 'selection', 'mandatory' => true],
                ['name' => 'Remarks', 'type' => 'text', 'mandatory' => false],
            ],
        ],
        [
            'title' => 'AIE Allocations Analysis',
            'type' => 'structured_table',
            'guidance' => null,
            'schema' => [
                ['name' => 'Budget Code', 'type' => 'text', 'mandatory' => true],
                ['name' => 'Head Description', 'type' => 'text', 'mandatory' => true],
                ['name' => 'Quarter Allocation', 'type' => 'numeric', 'mandatory' => true],
                ['name' => 'Deficit/Surplus', 'type' => 'numeric', 'mandatory' => false],
                ['name' => 'Remarks', 'type' => 'text', 'mandatory' => false],
            ],
        ],
        ['title' => 'Conclusion', 'type' => 'narrative', 'guidance' => 'Summary of key issues', 'schema' => null],
        ['title' => 'Recommendation', 'type' => 'narrative', 'guidance' => 'Action points recommended to SDT', 'schema' => null],
        ['title' => 'Reference', 'type' => 'narrative', 'guidance' => 'Reference material cited', 'schema' => null],
    ];

    /**
     * Seed version 1 of the SDT quarterly report template.
     */
    public function run(): void
    {
        $ministryId = DB::table('ministries')
            ->where('name', 'State Department for Trade')
            ->value('id');

        if (! $ministryId) {
            throw new \RuntimeException('SDT ministry not found; run MinistrySeeder first.');
        }

        foreach ($this->sections as $index => $section) {
            $sectionOrder = $index + 1;

            $exists = DB::table('report_template_sections')
                ->where('ministry_id', $ministryId)
                ->where('version', self::VERSION)
                ->where('section_order', $sectionOrder)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('report_template_sections')->insert([
                'ministry_id' => $ministryId,
                'version' => self::VERSION,
                'effective_date' => now()->toDateString(),
                'section_order' => $sectionOrder,
                'section_title' => $section['title'],
                'section_type' => $section['type'],
                'column_schema' => $section['schema'] ? json_encode($section['schema']) : null,
                'guidance_text' => $section['guidance'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
