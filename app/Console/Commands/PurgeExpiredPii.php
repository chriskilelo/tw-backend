<?php

namespace App\Console\Commands;

use App\Services\InquiryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * NFR-DATA-002 session task: redacts inquirer PII (name, email, phone) on
 * closed inquiries older than the configured retention period
 * (InquiryService::PII_RETENTION_MONTHS). Scheduled daily
 * (routes/console.php) purely so the 12-month threshold tracks today's
 * date automatically; safe to re-run any number of times —
 * InquiryService::purgeExpiredPii() only touches rows not already
 * redacted, so a row is never re-purged or double-logged.
 */
#[Signature('tw:purge-pii')]
#[Description('Redacts inquirer PII on closed inquiries older than the configured retention period (NFR-DATA-002).')]
class PurgeExpiredPii extends Command
{
    public function __construct(private readonly InquiryService $inquiryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $purgedCount = $this->inquiryService->purgeExpiredPii();

        $this->info("Redacted inquirer PII on {$purgedCount} closed inquiries.");

        return self::SUCCESS;
    }
}
