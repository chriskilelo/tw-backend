<?php

namespace Database\Seeders;

use App\Models\Mission;
use App\Services\DirectiveService;
use Database\Seeders\Support\DemoManifest;
use Database\Seeders\Support\FiscalQuarters;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds 22 individually-authored, real-policy-grounded directives (AGOA
 * renewal advocacy, the Kenya-China avocado protocol, the UK-Kenya EPA,
 * AfCFTA, the draft Kenya-UAE labour agreement, EAC/COMESA corridor
 * disputes, etc.) via the real DirectiveService, backdated per-directive.
 * Each carries an explicit `outcome` that determines its final state:
 * completed on time, completed late, left stale/overdue (in_progress with
 * no recent update), genuinely fresh/in-progress (the one directive
 * anchored to the current quarter), or cancelled.
 */
class DemoDirectiveSeeder extends Seeder
{
    /**
     * @var array<int, array{mission: string, quarter: int, type: string, description: string, days: int, outcome: string, summary?: string}>
     */
    private const array DIRECTIVES = [
        ['mission' => 'Washington D.C.', 'quarter' => 4, 'type' => 'AGOA Advocacy', 'description' => 'Coordinate advocacy materials and Congressional briefings ahead of the AGOA reauthorisation vote, including updated Kenyan export performance data.', 'days' => 45, 'outcome' => 'completed_on_time', 'summary' => 'Briefing pack and updated export data delivered to the Corporate Council on Africa and relevant Congressional offices ahead of schedule.'],
        ['mission' => 'Beijing', 'quarter' => 0, 'type' => 'Market Access Compliance', 'description' => 'Complete a compliance audit of avocado packhouse cold-chain certification ahead of the 2024/25 export season, coordinating with GACC and KEPHIS.', 'days' => 30, 'outcome' => 'completed_on_time', 'summary' => 'Audit completed jointly with GACC and KEPHIS; three packhouses certified ahead of the export season opening.'],
        ['mission' => 'London', 'quarter' => 0, 'type' => 'Trade Agreement Review', 'description' => 'Submit a utilisation review of the UK-Kenya EPA\'s tariff lines for processed foods, identifying under-used preferences for HQ follow-up.', 'days' => 30, 'outcome' => 'completed_on_time', 'summary' => 'Utilisation review submitted; three under-used tariff lines flagged for HQ exporter-outreach follow-up.'],
        ['mission' => 'Berlin', 'quarter' => 2, 'type' => 'Regulatory Compliance', 'description' => 'Brief all Kenyan coffee cooperative contacts on the EU Deforestation Regulation\'s geolocation data requirements ahead of the compliance deadline.', 'days' => 40, 'outcome' => 'completed_on_time', 'summary' => 'Briefing delivered to all cooperative contacts; geolocation data template circulated ahead of the compliance deadline.'],
        ['mission' => 'Accra', 'quarter' => 4, 'type' => 'AfCFTA Engagement', 'description' => 'Secure Kenyan tea and coffee\'s inclusion on the AfCFTA Guided Trade Initiative\'s pilot product list.', 'days' => 50, 'outcome' => 'completed_on_time', 'summary' => 'Kenyan tea confirmed on the Guided Trade Initiative pilot list; coffee application submitted for the next review cycle.'],
        ['mission' => 'New Delhi', 'quarter' => 4, 'type' => 'Digital Economy Partnership', 'description' => 'Convene the Kenya-India Joint Trade Committee\'s digital economy working group and produce a scoping note on UPI-style payment rail interoperability.', 'days' => 45, 'outcome' => 'completed_on_time', 'summary' => 'Working group convened; scoping note delivered to HQ recommending a follow-up technical mission.'],
        ['mission' => 'Dubai', 'quarter' => 5, 'type' => 'Bilateral Labour Agreement', 'description' => 'Advance the draft Kenya-UAE bilateral labour agreement to a second round of technical negotiations, incorporating domestic and skilled worker welfare protections.', 'days' => 60, 'outcome' => 'completed_on_time', 'summary' => 'Second round of technical negotiations concluded; draft text incorporating worker welfare protections forwarded for Cabinet-level review.'],
        ['mission' => 'Kinshasa', 'quarter' => 2, 'type' => 'Trade Corridor Facilitation', 'description' => 'Produce a briefing on Kasumbalesa border corridor delays affecting Kenyan exporters, with recommended mitigation steps.', 'days' => 21, 'outcome' => 'stale'],
        ['mission' => 'Lusaka', 'quarter' => 1, 'type' => 'Trade Corridor Facilitation', 'description' => 'Coordinate with COMESA Secretariat counterparts on resolving the outstanding pharmaceuticals payment dispute affecting a Kenyan exporter.', 'days' => 21, 'outcome' => 'completed_late', 'summary' => 'Dispute resolved via COMESA\'s trade remedies desk after an extended delay caused by the mission\'s single-officer caseload backlog.'],
        ['mission' => 'Arusha', 'quarter' => 3, 'type' => 'Non-Tariff Barrier Resolution', 'description' => 'Escalate and resolve the outstanding Namanga weighbridge non-tariff barrier complaint through the EAC Secretariat\'s mechanism.', 'days' => 21, 'outcome' => 'completed_late', 'summary' => 'Resolved via the EAC non-tariff barriers online reporting mechanism, several months later than the original target given competing single-officer priorities.'],
        ['mission' => 'Islamabad', 'quarter' => 5, 'type' => 'Trade Facilitation', 'description' => 'Resolve the outstanding Karachi tea-grading dispute in coordination with the Pakistan Tea Association.', 'days' => 30, 'outcome' => 'stale'],
        ['mission' => 'Brasilia', 'quarter' => 4, 'type' => 'Agri-Tech Cooperation', 'description' => 'Advance the EMBRAPA agri-tech cooperation MOU to a working draft ready for Nairobi\'s legal review.', 'days' => 45, 'outcome' => 'completed_late', 'summary' => 'Working draft completed and forwarded to Nairobi, delayed by the Portuguese-language legal review the mission\'s single-officer post had to arrange locally.'],
        ['mission' => 'Cairo', 'quarter' => 3, 'type' => 'Logistics Partnership', 'description' => 'Produce a briefing on Suez Canal Authority transit-fee changes and their impact on Kenyan cargo costs.', 'days' => 21, 'outcome' => 'completed_on_time', 'summary' => 'Briefing delivered to HQ with updated freight-cost projections for the coming shipping season.'],
        ['mission' => 'Pretoria', 'quarter' => 4, 'type' => 'Export Mission', 'description' => 'Coordinate a Kenyan mining-equipment sourcing delegation\'s visit to Johannesburg suppliers.', 'days' => 30, 'outcome' => 'completed_on_time', 'summary' => 'Delegation visit completed; two supplier agreements progressed to term-sheet stage.'],
        ['mission' => 'Kampala', 'quarter' => 2, 'type' => 'Border Post Facilitation', 'description' => 'Support resolution of the Busia phytosanitary non-tariff barrier complaint through the EAC mechanism.', 'days' => 21, 'outcome' => 'completed_on_time', 'summary' => 'Complaint resolved through the EAC non-tariff barriers mechanism within the target window.'],
        ['mission' => 'Jakarta', 'quarter' => 4, 'type' => 'Market Study', 'description' => 'Commission and deliver a market study comparing Indonesian palm oil imports against Kenya\'s domestic edible-oils sourcing strategy.', 'days' => 45, 'outcome' => 'completed_on_time', 'summary' => 'Market study delivered and briefed to Nairobi-based edible-oils processors.'],
        ['mission' => 'Addis Ababa', 'quarter' => 4, 'type' => 'Multilateral Coordination', 'description' => 'Coordinate Kenya\'s delegation logistics for the African Union Trade Ministers\' side-event on continental coffee-sector cooperation.', 'days' => 30, 'outcome' => 'completed_on_time', 'summary' => 'Delegation logistics coordinated successfully; side-event delivered without incident.'],
        ['mission' => 'Washington D.C.', 'quarter' => 7, 'type' => 'State Visit Coordination', 'description' => 'Coordinate trade-delegation logistics for the upcoming Presidential state visit, including a business-to-business forum for Kenyan manufacturers.', 'days' => 40, 'outcome' => 'completed_on_time', 'summary' => 'State visit trade logistics and the manufacturers\' B2B forum delivered on schedule.'],
        ['mission' => 'Beijing', 'quarter' => 6, 'type' => 'Market Access Expansion', 'description' => 'Prepare a formal submission to GACC requesting expansion of the avocado phytosanitary protocol to cover additional processing facilities.', 'days' => 45, 'outcome' => 'completed_on_time', 'summary' => 'Submission delivered to GACC; response pending under standard review timelines.'],
        ['mission' => 'Lusaka', 'quarter' => 6, 'type' => 'Asset Register Audit', 'description' => 'Complete a full mission asset register audit and submit updated equipment status records to HQ.', 'days' => 21, 'outcome' => 'stale'],
        ['mission' => 'Kinshasa', 'quarter' => 8, 'type' => 'Security Contingency Planning', 'description' => 'Submit an updated transport-security contingency plan for Kenyan traders operating on the Kinshasa-Lubumbashi route.', 'days' => 30, 'outcome' => 'in_progress'],
        ['mission' => 'Jakarta', 'quarter' => 6, 'type' => 'Trade Fair Coordination', 'description' => 'Coordinate Kenyan exporter participation in a planned Jakarta trade fair pavilion.', 'days' => 30, 'outcome' => 'cancelled'],
    ];

    public function seed(DemoManifest $manifest, array $roster): void
    {
        $directiveService = app(DirectiveService::class);
        $quarters = FiscalQuarters::reportQuarters();
        $ps = $roster['hq']['ps'];

        foreach (self::DIRECTIVES as $spec) {
            $mission = Mission::query()->where('name', $spec['mission'])->firstOrFail();
            $target = $roster['primary'][$spec['mission']];
            $quarter = $quarters[$spec['quarter']];

            $issueMoment = $quarter['start']->copy()->addDays(10);
            Carbon::setTestNow($issueMoment);

            $directive = $directiveService->issueDirective([
                'mission_id' => $mission->id,
                'target_user_id' => $target->id,
                'type_category' => $spec['type'],
                'description' => $spec['description'],
                'target_completion_date' => $issueMoment->copy()->addDays($spec['days'])->toDateString(),
            ], $ps);

            $manifest->add('directives', $directive->id);

            $this->applyOutcome($directiveService, $directive, $target, $issueMoment, $spec);

            Carbon::setTestNow();
        }
    }

    /**
     * @param  array{mission: string, quarter: int, type: string, description: string, days: int, outcome: string, summary?: string}  $spec
     */
    private function applyOutcome(DirectiveService $directiveService, $directive, $target, Carbon $issueMoment, array $spec): void
    {
        Carbon::setTestNow($issueMoment->copy()->addDays(3));
        $directiveService->transitionStatus($directive->fresh(), 'acknowledged', $target, 'Directive acknowledged; commencing work.');

        Carbon::setTestNow($issueMoment->copy()->addDays(7));
        $directiveService->transitionStatus($directive->fresh(), 'in_progress', $target, 'Work underway.');

        match ($spec['outcome']) {
            'completed_on_time' => $this->complete($directiveService, $directive, $target, $issueMoment->copy()->addDays(max(8, $spec['days'] - 5)), $spec['summary']),
            'completed_late' => $this->complete($directiveService, $directive, $target, $issueMoment->copy()->addDays($spec['days'] + 25), $spec['summary']),
            'cancelled' => $this->cancel($directiveService, $directive, $target, $issueMoment->copy()->addDays(12)),
            default => null, // 'stale' and 'in_progress': left as-is after the in_progress transition above.
        };
    }

    private function complete(DirectiveService $directiveService, $directive, $target, Carbon $moment, string $summary): void
    {
        Carbon::setTestNow($moment);
        $directiveService->transitionStatus($directive->fresh(), 'completed', $target, $summary);
    }

    private function cancel(DirectiveService $directiveService, $directive, $target, Carbon $moment): void
    {
        Carbon::setTestNow($moment);
        $directiveService->transitionStatus($directive->fresh(), 'cancelled', $target, 'Cancelled following a budget reallocation; the planned trade fair pavilion did not proceed this cycle.');
    }
}
