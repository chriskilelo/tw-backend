<?php

namespace Database\Seeders;

use App\Models\Inquiry;
use App\Models\Mission;
use App\Models\ReferralOrganisation;
use App\Models\User;
use App\Services\InquiryService;
use App\Services\ReferralService;
use Database\Seeders\Support\DemoManifest;
use Database\Seeders\Support\Determinism;
use Database\Seeders\Support\FiscalQuarters;
use Database\Seeders\Support\MissionTradeProfiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seeds ~250 trade/investment inquiries (1-2 per mission per quarter) via
 * the real InquiryService, backdated with Carbon::setTestNow(). Inquirer
 * names and companies are drawn from region-appropriate name pools (never
 * Kenyan names — inquirers are the foreign buyers/investors/counterparts a
 * mission deals with) so they read as plausible foreign contacts rather
 * than placeholder text. A handful showcase disputes, high-value flags,
 * referrals to the real seeded referral organisations, and one
 * cross-mission link (FR-INQ-019).
 */
class DemoInquirySeeder extends Seeder
{
    private const int CURRENT_QUARTER_INDEX = 8;

    private const array CATEGORIES = [
        'Buyer Seeking Supplier',
        'Investor Seeking Partner',
        'General Market Question',
        'Investor Seeking Buyer',
        'Investor Seeking Investment Opportunities',
    ];

    /**
     * @var array<string, string>
     */
    private const array MISSION_REGION = [
        'Washington D.C.' => 'western', 'London' => 'western', 'Berlin' => 'western',
        'New Delhi' => 'south_asian', 'Islamabad' => 'south_asian',
        'Beijing' => 'east_asian', 'Jakarta' => 'southeast_asian',
        'Dubai' => 'arabic', 'Cairo' => 'arabic',
        'Brasilia' => 'latin',
        'Kinshasa' => 'francophone_african',
        'Arusha' => 'anglophone_african', 'Lusaka' => 'anglophone_african',
        'Pretoria' => 'anglophone_african', 'Accra' => 'anglophone_african', 'Kampala' => 'anglophone_african',
        'Addis Ababa' => 'ethiopian',
    ];

    /**
     * @var array<string, array{first: array<int, string>, last: array<int, string>, suffix: array<int, string>}>
     */
    private const array NAME_POOLS = [
        'western' => [
            'first' => ['James', 'Emily', 'Michael', 'Sarah', 'David', 'Laura', 'Robert', 'Anna'],
            'last' => ['Whitfield', 'Harrington', 'Bennett', 'Mercer', 'Coleman', 'Sinclair', 'Fairweather', 'Holt'],
            'suffix' => ['Inc.', 'LLC', 'Group', 'Ltd', '& Co.'],
        ],
        'south_asian' => [
            'first' => ['Rajesh', 'Priya', 'Amit', 'Sunita', 'Vikram', 'Anjali', 'Farhan', 'Ayesha'],
            'last' => ['Sharma', 'Mehta', 'Khan', 'Verma', 'Malhotra', 'Qureshi', 'Iyer', 'Chaudhry'],
            'suffix' => ['Pvt Ltd', 'Traders', '& Sons', 'Exports'],
        ],
        'east_asian' => [
            'first' => ['Wei', 'Mei', 'Jun', 'Li', 'Feng', 'Yan', 'Hao', 'Xin'],
            'last' => ['Zhang', 'Chen', 'Wang', 'Liu', 'Huang', 'Zhou', 'Wu', 'Sun'],
            'suffix' => ['Co., Ltd', 'Import & Export Corp', 'Trading Co.', 'Group'],
        ],
        'southeast_asian' => [
            'first' => ['Budi', 'Siti', 'Andi', 'Dewi', 'Agus', 'Rina', 'Hendra', 'Wulan'],
            'last' => ['Santoso', 'Wijaya', 'Kurniawan', 'Suryanto', 'Halim', 'Pratama', 'Wibowo', 'Setiawan'],
            'suffix' => ['PT', 'Trading', 'Group', 'Niaga'],
        ],
        'arabic' => [
            'first' => ['Ahmed', 'Fatima', 'Khalid', 'Layla', 'Omar', 'Noura', 'Hassan', 'Rana'],
            'last' => ['Al-Farsi', 'Mansour', 'Haddad', 'Saleh', 'Al-Rashid', 'Nasser', 'Qureshi', 'Aziz'],
            'suffix' => ['Trading LLC', 'General Trading', 'Group', '& Partners'],
        ],
        'latin' => [
            'first' => ['Carlos', 'Fernanda', 'Rafael', 'Camila', 'Bruno', 'Juliana', 'Eduardo', 'Larissa'],
            'last' => ['Silva', 'Oliveira', 'Santos', 'Pereira', 'Costa', 'Almeida', 'Ribeiro', 'Carvalho'],
            'suffix' => ['Ltda', 'Comercio', 'Importadora', 'S.A.'],
        ],
        'francophone_african' => [
            'first' => ['Jean-Pierre', 'Marie', 'Emmanuel', 'Grace', 'Patrice', 'Josephine', 'Didier', 'Chantal'],
            'last' => ['Kabongo', 'Mwamba', 'Tshisekedi', 'Ilunga', 'Kalonji', 'Mukendi', 'Ngoy', 'Kasongo'],
            'suffix' => ['SARL', 'Negoce', 'Import-Export', 'Group'],
        ],
        'anglophone_african' => [
            'first' => ['Kwame', 'Abena', 'Samuel', 'Grace', 'Joseph', 'Patience', 'Michael', 'Comfort'],
            'last' => ['Osei', 'Mensah', 'Banda', 'Phiri', 'Mokoena', 'Dlamini', 'Nkosi', 'Owusu'],
            'suffix' => ['Ltd', 'Traders', 'Enterprises', 'Group'],
        ],
        'ethiopian' => [
            'first' => ['Abebe', 'Tigist', 'Dawit', 'Selamawit', 'Yonas', 'Hirut', 'Solomon', 'Meron'],
            'last' => ['Bekele', 'Alemu', 'Tesfaye', 'Girma', 'Haile', 'Wolde', 'Assefa', 'Tadesse'],
            'suffix' => ['PLC', 'Trading', 'Import-Export', 'Share Company'],
        ],
    ];

    private const array COMPANY_PREFIXES = ['Meridian', 'Union', 'Continental', 'Pacific', 'Golden', 'Heritage', 'Summit', 'Crestview', 'Northgate', 'Silverline', 'Horizon', 'Anchor', 'Cedar', 'Prime', 'Regency'];

    public function seed(DemoManifest $manifest, array $roster): void
    {
        $inquiryService = app(InquiryService::class);
        $referralService = app(ReferralService::class);
        $quarters = FiscalQuarters::reportQuarters();
        $referralOrgs = ReferralOrganisation::query()->withoutGlobalScopes()->get();

        $linkCandidates = [];

        foreach (MissionTradeProfiles::all() as $missionName => $profile) {
            $mission = Mission::query()->where('name', $missionName)->firstOrFail();
            $attaches = array_merge([$roster['primary'][$missionName]], $roster['junior'][$missionName]);
            $region = self::MISSION_REGION[$missionName];

            foreach ($quarters as $index => $quarter) {
                $isCurrent = $index === self::CURRENT_QUARTER_INDEX;
                $count = $isCurrent ? 1 : Determinism::seeded("{$missionName}-{$index}-inqcount", 1, 2);

                for ($n = 0; $n < $count; $n++) {
                    $inquiry = $this->seedOneInquiry(
                        $manifest, $inquiryService, $referralService, $roster,
                        $mission, $profile, $missionName, $region, $attaches,
                        $index, $quarter, $n, $isCurrent, $referralOrgs,
                    );

                    if ($inquiry !== null && count($linkCandidates) < 2 && $index === 2) {
                        $linkCandidates[] = ['inquiry' => $inquiry, 'attache' => $attaches[0]];
                    }
                }
            }
        }

        if (count($linkCandidates) === 2) {
            Carbon::setTestNow(FiscalQuarters::reportQuarters()[2]['start']->copy()->addDays(40));
            $inquiryService->linkInquiry($linkCandidates[0]['inquiry'], $linkCandidates[1]['inquiry'], $linkCandidates[0]['attache']);
            Carbon::setTestNow();
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, User>  $attaches
     * @param  array{label: string, fy: string, start: Carbon, end: Carbon, deadline: Carbon}  $quarter
     * @param  Collection<int, ReferralOrganisation>  $referralOrgs
     */
    private function seedOneInquiry(
        DemoManifest $manifest,
        InquiryService $inquiryService,
        ReferralService $referralService,
        array $roster,
        Mission $mission,
        array $profile,
        string $missionName,
        string $region,
        array $attaches,
        int $index,
        array $quarter,
        int $sequence,
        bool $isCurrent,
        $referralOrgs,
    ): ?Inquiry {
        $seedKey = "{$missionName}-{$index}-inq{$sequence}";
        $logger = Determinism::pick($seedKey.'-logger', $attaches);

        $windowEnd = $isCurrent ? FiscalQuarters::currentDateCap() : $quarter['end'];
        $span = max(1, $windowEnd->diffInDays($quarter['start']));
        $receivedMoment = $quarter['start']->copy()->addDays(Determinism::seeded($seedKey.'-day', 0, (int) $span));
        Carbon::setTestNow($receivedMoment);

        $isDispute = Determinism::chance($seedKey.'-dispute', 8);
        $category = $isDispute ? 'Disputes/Complaints' : Determinism::pick($seedKey.'-category', self::CATEGORIES);
        $pool = self::NAME_POOLS[$region];
        $inquirerName = Determinism::pick($seedKey.'-fname', $pool['first']).' '.Determinism::pick($seedKey.'-lname', $pool['last']);
        $company = Determinism::pick($seedKey.'-prefix', self::COMPANY_PREFIXES).' '.
            ucfirst(Determinism::pick($seedKey.'-export', $profile['exports'])).' '.
            Determinism::pick($seedKey.'-suffix', $pool['suffix']);
        $export = Determinism::pick($seedKey.'-export2', $profile['exports']);
        $sector = Determinism::pick($seedKey.'-sector', $profile['sectors']);
        $isHighValue = Determinism::chance($seedKey.'-highvalue', 15);

        $inquiry = $inquiryService->logInquiry([
            'category' => $category,
            'sub_type' => $isDispute ? 'dispute_or_complaint' : 'standard',
            'inquirer_name' => $inquirerName,
            'inquirer_organisation' => $company,
            'inquirer_email' => strtolower(str_replace(' ', '.', $inquirerName)).'@'.strtolower(str_replace([' ', ','], ['', ''], preg_replace('/[^A-Za-z ]/', '', $company))).'.com',
            'inquirer_phone' => null,
            'product_or_sector' => ucfirst($export),
            'description' => $this->descriptionFor($category, $company, $export, $sector, $mission->host_country, $isDispute),
            'date_received' => $receivedMoment->toDateString(),
            'high_value_flag' => $isHighValue,
            'high_value_justification' => $isHighValue ? 'Prospective contract value estimated in excess of USD 500,000 annually.' : null,
        ], $logger);

        $manifest->add('inquiries', $inquiry->id);

        $this->progressInquiry($inquiryService, $referralService, $roster, $referralOrgs, $inquiry, $logger, $missionName, $seedKey, $receivedMoment, $isCurrent, $isDispute);

        Carbon::setTestNow();

        return $inquiry;
    }

    private function descriptionFor(string $category, string $company, string $export, string $sector, string $hostCountry, bool $isDispute): string
    {
        if ($isDispute) {
            return "{$company} has raised a formal complaint regarding a quality/delivery discrepancy on a recent {$export} shipment, and is requesting mission-facilitated resolution with the Kenyan supplier.";
        }

        return match ($category) {
            'Buyer Seeking Supplier' => "{$company}, a {$sector} importer based in {$hostCountry}, is seeking a reliable Kenyan supplier of {$export} for regular shipments.",
            'Investor Seeking Partner' => "{$company} is exploring a joint-venture partnership with a Kenyan {$sector} firm to establish local processing or distribution capacity.",
            'General Market Question' => "{$company} requested guidance on Kenya's export documentation and certification requirements for {$export} shipments into {$hostCountry}.",
            'Investor Seeking Buyer' => "{$company} is seeking buyer connections for {$export}-related output following a recent investment commitment in the sector.",
            'Investor Seeking Investment Opportunities' => "{$company} has expressed interest in identifying investment opportunities within Kenya's {$sector} sector.",
            default => "{$company} contacted the mission regarding {$export} trade opportunities.",
        };
    }

    private function progressInquiry(
        InquiryService $inquiryService,
        ReferralService $referralService,
        array $roster,
        $referralOrgs,
        Inquiry $inquiry,
        $logger,
        string $missionName,
        string $seedKey,
        Carbon $receivedMoment,
        bool $isCurrent,
        bool $isDispute,
    ): void {
        Carbon::setTestNow($receivedMoment->copy()->addDays(1));
        $inquiryService->transitionStatus($inquiry->fresh(), 'received', $logger);

        if ($isCurrent && Determinism::chance($seedKey.'-stopatreceived', 40)) {
            return;
        }

        Carbon::setTestNow($receivedMoment->copy()->addDays(Determinism::seeded($seedKey.'-progdays', 3, 10)));
        $inquiryService->transitionStatus($inquiry->fresh(), 'in_progress', $logger);
        $inquiryService->logEvent($inquiry->fresh(), 'hq_notified', $logger, 'HQ notified of new inquiry for awareness.');

        if (Determinism::chance($seedKey.'-referral', 15)) {
            $org = Determinism::pick($seedKey.'-refuorg', $referralOrgs->all());
            Carbon::setTestNow($receivedMoment->copy()->addDays(Determinism::seeded($seedKey.'-refdays', 11, 18)));
            $referralService->recordReferral($inquiry->fresh(), $org, [
                'referral_date' => Carbon::now()->toDateString(),
                'contact_person' => Determinism::pick($seedKey.'-refcontact', ['Front Office Desk', 'Trade Facilitation Unit', 'Client Relations Officer']),
                'referral_method' => 'Email introduction',
                'remarks' => "Referred for specialised support given the inquiry's scope.",
            ], $logger);
            $inquiryService->logEvent($inquiry->fresh(), 'referral_made', $logger, "Referred to {$org->name}.");
        }

        if ($isCurrent) {
            return;
        }

        if ($isDispute && Determinism::chance($seedKey.'-cancel', 10)) {
            Carbon::setTestNow($receivedMoment->copy()->addDays(Determinism::seeded($seedKey.'-canceldays', 20, 35)));
            $inquiryService->transitionStatus($inquiry->fresh(), 'cancelled', $logger);

            return;
        }

        if (Determinism::chance($seedKey.'-staysopen', 12)) {
            Carbon::setTestNow($receivedMoment->copy()->addDays(Determinism::seeded($seedKey.'-pendingdays', 15, 25)));
            $inquiryService->transitionStatus($inquiry->fresh(), 'pending_external_response', $logger);

            return;
        }

        Carbon::setTestNow($receivedMoment->copy()->addDays(Determinism::seeded($seedKey.'-resolvedays', 15, 30)));
        $inquiryService->transitionStatus($inquiry->fresh(), 'resolved', $logger);

        Carbon::setTestNow($receivedMoment->copy()->addDays(Determinism::seeded($seedKey.'-closedays', 22, 40)));
        $inquiryService->closeInquiry(
            $inquiry->fresh(),
            $isDispute
                ? 'Resolved in coordination with the Kenyan supplier; replacement shipment agreed and dispatched to the client\'s satisfaction.'
                : 'Inquiry closed after successful facilitation; the parties connected directly to progress terms.',
            $logger,
        );
    }
}
