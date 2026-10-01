<?php

use App\Support\KpiPeriod;
use Carbon\CarbonImmutable;

/**
 * App\Support\KpiPeriod: the fiscal calendar KPIs are measured in
 * (CLAUDE.md Section 8) — Q1 Jul-Sep ... Q4 Apr-Jun, labelled by each
 * quarter's start-month year; H1 = Q1+Q2, H2 = Q3+Q4.
 */
it('maps quarter and half-year labels to their fiscal dates (TC-FR-KPI-016-D)', function () {
    $q1 = KpiPeriod::fromLabel('Q1 2026');
    $q3 = KpiPeriod::fromLabel('Q3 2026');
    $h1 = KpiPeriod::fromLabel('H1 2026');
    $h2 = KpiPeriod::fromLabel('H2 2026');

    expect($q1->start()->toDateString())->toBe('2026-07-01')
        ->and($q1->end()->toDateString())->toBe('2026-09-30')
        ->and($q3->start()->toDateString())->toBe('2026-01-01')
        ->and($q3->end()->toDateString())->toBe('2026-03-31')
        ->and($h1->start()->toDateString())->toBe('2026-07-01')
        ->and($h1->end()->toDateString())->toBe('2026-12-31')
        ->and(array_column($h1->quarters, 'label'))->toBe(['Q1 2026', 'Q2 2026'])
        ->and($h2->start()->toDateString())->toBe('2026-01-01')
        ->and(array_column($h2->quarters, 'label'))->toBe(['Q3 2026', 'Q4 2026']);
});

it('rejects malformed and out-of-range labels', function (string $label) {
    expect(KpiPeriod::isLabel($label))->toBeFalse()
        ->and(fn () => KpiPeriod::fromLabel($label))->toThrow(InvalidArgumentException::class);
})->with(['Q5 2026', 'H3 2026', 'q1 2026', 'Q1 26', 'Q1  2026', '', 'garbage', 'Q1 1999', 'H1 2101']);

it('finds the quarter and half-year containing a date', function (string $date, string $quarter, string $half) {
    $day = CarbonImmutable::parse($date);

    expect(KpiPeriod::quarterContaining($day)->label)->toBe($quarter)
        ->and(KpiPeriod::halfContaining($day)->label)->toBe($half);
})->with([
    ['2026-07-01', 'Q1 2026', 'H1 2026'],
    ['2026-09-30', 'Q1 2026', 'H1 2026'],
    ['2026-12-31', 'Q2 2026', 'H1 2026'],
    ['2027-01-01', 'Q3 2027', 'H2 2027'],
    ['2027-06-30', 'Q4 2027', 'H2 2027'],
]);

it('steps to the previous and next period of the same shape', function () {
    expect(KpiPeriod::fromLabel('Q3 2027')->previous()->label)->toBe('Q2 2026')
        ->and(KpiPeriod::fromLabel('Q2 2026')->next()->label)->toBe('Q3 2027')
        ->and(KpiPeriod::fromLabel('H1 2026')->previous()->label)->toBe('H2 2026')
        ->and(KpiPeriod::fromLabel('H2 2026')->previous()->label)->toBe('H1 2025')
        ->and(KpiPeriod::fromLabel('H1 2026')->next()->label)->toBe('H2 2027');
});

it('snaps a custom date range to whole quarters (TC-FR-KPI-009-C)', function () {
    $range = KpiPeriod::between(CarbonImmutable::parse('2025-08-15'), CarbonImmutable::parse('2026-02-03'));

    expect($range->type)->toBe(KpiPeriod::RANGE)
        ->and(array_column($range->quarters, 'label'))->toBe(['Q1 2025', 'Q2 2025', 'Q3 2026'])
        ->and($range->label)->toBe('Q1 2025 – Q3 2026')
        ->and($range->previous()->label)->toBe('Q2 2024 – Q4 2025')
        ->and(KpiPeriod::between(CarbonImmutable::parse('2026-07-02'), CarbonImmutable::parse('2026-08-30'))->type)->toBe(KpiPeriod::QUARTER);
});

it('refuses a reversed or over-long custom range', function () {
    expect(fn () => KpiPeriod::between(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-01-01')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => KpiPeriod::between(CarbonImmutable::parse('2020-07-01'), CarbonImmutable::parse('2026-06-30'), 12))->toThrow(InvalidArgumentException::class);
});

it('settles a quarter only after its reporting deadline, the 15th of the next month (TC-FR-KPI-008-D)', function () {
    $half = KpiPeriod::fromLabel('H1 2026');

    expect($half->phase(CarbonImmutable::parse('2026-06-30')))->toBe(KpiPeriod::UPCOMING)
        ->and($half->phase(CarbonImmutable::parse('2026-10-01')))->toBe(KpiPeriod::IN_PROGRESS)
        ->and($half->progress(CarbonImmutable::parse('2026-10-15')))->toBe(0.0)
        ->and($half->progress(CarbonImmutable::parse('2026-10-16')))->toBe(0.5)
        ->and($half->phase(CarbonImmutable::parse('2027-01-10')))->toBe(KpiPeriod::COMPLETE)
        ->and($half->isFinal(CarbonImmutable::parse('2027-01-15')))->toBeFalse()
        ->and($half->isFinal(CarbonImmutable::parse('2027-01-16')))->toBeTrue()
        ->and($half->progress(CarbonImmutable::parse('2027-01-16')))->toBe(1.0);
});

it('names the half-year cycle each quarter belongs to', function () {
    expect(KpiPeriod::halfLabelForQuarter('Q1 2026'))->toBe('H1 2026')
        ->and(KpiPeriod::halfLabelForQuarter('Q2 2026'))->toBe('H1 2026')
        ->and(KpiPeriod::halfLabelForQuarter('Q3 2027'))->toBe('H2 2027')
        ->and(KpiPeriod::between(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-09-30'))->halfLabels())->toBe(['H2 2026', 'H1 2026']);
});
