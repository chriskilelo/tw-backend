<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A KPI reporting period built from whole fiscal quarters (CLAUDE.md
 * Section 8: Q1 Jul-Sep, Q2 Oct-Dec, Q3 Jan-Mar, Q4 Apr-Jun, each labelled
 * by its start month's calendar year). A period is one quarter, one
 * half-yearly performance cycle (H1 = Q1+Q2, H2 = Q3+Q4), or a custom run
 * of consecutive quarters (FR-KPI-009). KPI data is recorded per quarter,
 * so a custom date range always snaps to the quarters it touches.
 */
final class KpiPeriod
{
    public const string QUARTER = 'quarter';

    public const string HALF = 'half';

    public const string RANGE = 'range';

    public const string COMPLETE = 'complete';

    public const string IN_PROGRESS = 'in_progress';

    public const string UPCOMING = 'upcoming';

    private const string LABEL_PATTERN = '/^(Q[1-4]|H[12]) (\d{4})$/';

    /**
     * @param  array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>  $quarters
     */
    private function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly array $quarters,
    ) {}

    public static function isLabel(string $label): bool
    {
        return preg_match(self::LABEL_PATTERN, $label, $matches) === 1 && self::yearInRange((int) $matches[2]);
    }

    public static function isHalfLabel(string $label): bool
    {
        return self::isLabel($label) && str_starts_with($label, 'H');
    }

    /**
     * "Q1 2026" or "H1 2026".
     */
    public static function fromLabel(string $label): self
    {
        if (! self::isLabel($label)) {
            throw new InvalidArgumentException("'{$label}' is not a quarter (Q1 2026) or half-year (H1 2026) label.");
        }

        preg_match(self::LABEL_PATTERN, $label, $matches);
        $number = (int) substr($matches[1], 1);

        return $matches[1][0] === 'Q'
            ? self::quarter((int) $matches[2], $number)
            : self::half((int) $matches[2], $number);
    }

    public static function quarter(int $year, int $number): self
    {
        return new self(self::QUARTER, "Q{$number} {$year}", [self::quarterRef($year, $number)]);
    }

    public static function half(int $year, int $number): self
    {
        [$first, $second] = $number === 1 ? [1, 2] : [3, 4];

        return new self(self::HALF, "H{$number} {$year}", [self::quarterRef($year, $first), self::quarterRef($year, $second)]);
    }

    public static function quarterContaining(CarbonInterface $date): self
    {
        $number = match (true) {
            $date->month >= 7 && $date->month <= 9 => 1,
            $date->month >= 10 => 2,
            $date->month <= 3 => 3,
            default => 4,
        };

        return self::quarter($date->year, $number);
    }

    public static function halfContaining(CarbonInterface $date): self
    {
        return self::half($date->year, $date->month >= 7 ? 1 : 2);
    }

    /**
     * Every quarter from the one containing $from to the one containing $to.
     */
    public static function between(CarbonInterface $from, CarbonInterface $to, int $maxQuarters = 12): self
    {
        $first = self::quarterContaining($from)->quarters[0];
        $last = self::quarterContaining($to)->quarters[0];

        if ($first['start']->greaterThan($last['start'])) {
            throw new InvalidArgumentException('The range must start on or before it ends.');
        }

        $quarters = [];
        for ($cursor = $first['start']; $cursor->lessThanOrEqualTo($last['start']); $cursor = $cursor->addMonths(3)) {
            $quarters[] = self::quarterContaining($cursor)->quarters[0];
        }

        if (count($quarters) > $maxQuarters) {
            throw new InvalidArgumentException("A custom range may cover at most {$maxQuarters} quarters.");
        }

        if (count($quarters) === 1) {
            return new self(self::QUARTER, $quarters[0]['label'], $quarters);
        }

        return new self(self::RANGE, $quarters[0]['label'].' – '.$quarters[count($quarters) - 1]['label'], $quarters);
    }

    /**
     * The half-yearly performance cycle a quarter label belongs to: Q1 and
     * Q2 make H1, Q3 and Q4 make H2, all sharing the labelled year.
     */
    public static function halfLabelForQuarter(string $quarterLabel): string
    {
        [$unit, $year] = explode(' ', $quarterLabel, 2);

        return (in_array($unit, ['Q1', 'Q2'], true) ? 'H1 ' : 'H2 ').$year;
    }

    /**
     * @return array{label: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    public static function quarterRef(int $year, int $number): array
    {
        if ($number < 1 || $number > 4 || ! self::yearInRange($year)) {
            throw new InvalidArgumentException("Q{$number} {$year} is not a valid quarter.");
        }

        $start = CarbonImmutable::create($year, [1 => 7, 2 => 10, 3 => 1, 4 => 4][$number], 1)->startOfDay();

        return ['label' => "Q{$number} {$year}", 'start' => $start, 'end' => $start->addMonths(3)->subDay()];
    }

    public function start(): CarbonImmutable
    {
        return $this->quarters[0]['start'];
    }

    public function end(): CarbonImmutable
    {
        return $this->quarters[count($this->quarters) - 1]['end'];
    }

    public function quarterCount(): int
    {
        return count($this->quarters);
    }

    public function phase(CarbonInterface $today): string
    {
        return self::phaseOf($this->start(), $this->end(), $today);
    }

    public function completedQuarters(CarbonInterface $today): int
    {
        return count(array_filter($this->quarters, fn (array $quarter): bool => $quarter['end']->toDateString() < $today->toDateString()));
    }

    /**
     * The reporting deadline of a quarter ending on $end: the 15th of the
     * following month (CLAUDE.md Section 8, BR-010), the same date
     * PeriodicReport::deadlineForPeriodEnd() uses.
     */
    public static function deadlineFor(CarbonInterface $end): CarbonImmutable
    {
        return CarbonImmutable::instance($end)->startOfDay()->addDays(15);
    }

    /**
     * Quarters whose reporting deadline has passed. Quarterly reports are
     * the authoritative source (CLAUDE.md Section 8) and manual figures are
     * entered after a quarter closes, so a quarter's results are only
     * settled once its deadline is behind us.
     */
    public function settledQuarters(CarbonInterface $today): int
    {
        return count(array_filter($this->quarters, fn (array $quarter): bool => self::deadlineFor($quarter['end'])->toDateString() < $today->toDateString()));
    }

    /**
     * Share of the period's quarters already settled — the share of the
     * target a KPI is expected to have reached by now: 1 once every quarter
     * has passed its reporting deadline, 0 before the first one has.
     */
    public function progress(CarbonInterface $today): float
    {
        return $this->settledQuarters($today) / $this->quarterCount();
    }

    /**
     * True once the whole period has passed its last reporting deadline:
     * its results are final.
     */
    public function isFinal(CarbonInterface $today): bool
    {
        return $this->settledQuarters($today) === $this->quarterCount();
    }

    /**
     * The period of the same shape immediately before this one.
     */
    public function previous(): self
    {
        $dayBefore = $this->start()->subDay();

        return match ($this->type) {
            self::QUARTER => self::quarterContaining($dayBefore),
            self::HALF => self::halfContaining($dayBefore),
            default => self::between($this->start()->subMonths(3 * $this->quarterCount()), $dayBefore, $this->quarterCount()),
        };
    }

    public function next(): self
    {
        $dayAfter = $this->end()->addDay();

        return match ($this->type) {
            self::QUARTER => self::quarterContaining($dayAfter),
            self::HALF => self::halfContaining($dayAfter),
            default => self::between($dayAfter, $this->end()->addMonthsNoOverflow(3 * $this->quarterCount()), $this->quarterCount()),
        };
    }

    /**
     * @return array<int, string>
     */
    public function quarterStarts(): array
    {
        return array_map(fn (array $quarter): string => $quarter['start']->toDateString(), $this->quarters);
    }

    /**
     * @return array<int, string> the half-yearly cycles this period's quarters fall in
     */
    public function halfLabels(): array
    {
        return array_values(array_unique(array_map(fn (array $quarter): string => self::halfLabelForQuarter($quarter['label']), $this->quarters)));
    }

    /**
     * @return array{type: string, label: string, start: string, end: string, phase: string, progress: float, is_final: bool, completed_quarters: int, settled_quarters: int, quarter_count: int, quarters: array<int, array{label: string, start: string, end: string, deadline: string, phase: string, settled: bool}>}
     */
    public function toArray(CarbonInterface $today): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'start' => $this->start()->toDateString(),
            'end' => $this->end()->toDateString(),
            'phase' => $this->phase($today),
            'progress' => round($this->progress($today), 4),
            'is_final' => $this->isFinal($today),
            'completed_quarters' => $this->completedQuarters($today),
            'settled_quarters' => $this->settledQuarters($today),
            'quarter_count' => $this->quarterCount(),
            'quarters' => array_map(fn (array $quarter): array => [
                'label' => $quarter['label'],
                'start' => $quarter['start']->toDateString(),
                'end' => $quarter['end']->toDateString(),
                'deadline' => self::deadlineFor($quarter['end'])->toDateString(),
                'phase' => self::phaseOf($quarter['start'], $quarter['end'], $today),
                'settled' => self::deadlineFor($quarter['end'])->toDateString() < $today->toDateString(),
            ], $this->quarters),
        ];
    }

    private static function phaseOf(CarbonInterface $start, CarbonInterface $end, CarbonInterface $today): string
    {
        $day = $today->toDateString();

        return match (true) {
            $end->toDateString() < $day => self::COMPLETE,
            $start->toDateString() > $day => self::UPCOMING,
            default => self::IN_PROGRESS,
        };
    }

    private static function yearInRange(int $year): bool
    {
        return $year >= 2000 && $year <= 2100;
    }
}
