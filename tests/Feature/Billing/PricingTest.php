<?php

use App\Billing\Money;
use App\Billing\Pricing;
use Carbon\CarbonImmutable;

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('the annual total is the base fee plus purchased seats times the seat price', function (int $base, int $seatPrice, int $seats, int $expected) {
    expect(Pricing::annualTotal($base, $seatPrice, $seats))->toBe($expected);
})->with([
    'typical' => [100000, 12000, 10, 220000],
    'no base fee' => [0, 12000, 3, 36000],
    'free seats' => [50000, 0, 25, 50000],
    'large values stay exact' => [100_000_000_000, 100_000_000_000, 10000, 1_000_100_000_000_000],
]);

test('negative prices or seats are rejected', function () {
    expect(fn () => Pricing::annualTotal(-1, 100, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pricing::annualTotal(100, 100, -1))->toThrow(InvalidArgumentException::class);
});

test('an annual term ends one calendar year later and handles leap days and year ends', function (string $start, string $end, int $days) {
    $endsOn = Pricing::annualTermEnd(day($start));

    expect($endsOn->toDateString())->toBe($end)
        ->and(Pricing::daysBetween(day($start), $endsOn))->toBe($days);
})->with([
    'ordinary year' => ['2025-03-15', '2026-03-15', 365],
    'year containing 29 February' => ['2027-06-01', '2028-06-01', 366],
    'starting on 29 February' => ['2028-02-29', '2029-02-28', 365],
    'starting on 31 December' => ['2026-12-31', '2027-12-31', 365],
    'starting on 1 January of a leap year' => ['2028-01-01', '2029-01-01', 366],
]);

test('additional seats on the first day of a term cost the full annual seat price', function () {
    $proration = Pricing::prorate(3, 12000, day('2026-03-01'), day('2027-03-01'), day('2026-03-01'));

    expect($proration->amount)->toBe(36000)
        ->and($proration->remainingDays)->toBe(365)
        ->and($proration->totalDays)->toBe(365);
});

test('additional seats near the renewal date only cost the remaining days', function () {
    // Last covered day: 1 of 365 days remains. 12000 / 365 = 32.876… → 33 (half up).
    $proration = Pricing::prorate(1, 12000, day('2026-03-01'), day('2027-03-01'), day('2027-02-28'));

    expect($proration->amount)->toBe(33)
        ->and($proration->remainingDays)->toBe(1);
});

test('proration uses the actual number of days in a leap term', function () {
    // 2028 term has 366 days; on 2028-07-01, 184 days remain (Jul–Dec).
    $proration = Pricing::prorate(2, 36600, day('2028-01-01'), day('2029-01-01'), day('2028-07-01'));

    expect($proration->totalDays)->toBe(366)
        ->and($proration->remainingDays)->toBe(184)
        ->and($proration->amount)->toBe(36800); // 2 × 36600 × 184 / 366 = 36800 exactly
});

test('prorated amounts are rounded half up once, to the minor unit', function (int $price, int $remaining, int $expected) {
    // A 365-day term starting 2026-01-01; the date is chosen so that $remaining days remain.
    $on = day('2027-01-01')->subDays($remaining);

    expect(Pricing::prorate(1, $price, day('2026-01-01'), day('2027-01-01'), $on)->amount)->toBe($expected);
})->with([
    'exact amount' => [730, 1, 2],             // 730 / 365 = 2
    'small fraction rounds down' => [100, 1, 0], // 0.27…
    'just below half rounds down' => [365 * 5 + 182, 1, 5], // 5.4986…
    'just above half rounds up' => [365 * 5 + 183, 1, 6],   // 5.5013…
]);

test('an exact half minor unit is rounded up', function () {
    // 183 × 1 / 366 = 0.5 exactly (leap term).
    expect(Pricing::prorate(1, 183, day('2028-01-01'), day('2029-01-01'), day('2028-12-31'))->amount)->toBe(1);
});

test('proration is rejected for zero seats and for dates outside the term', function (int $seats, string $on) {
    expect(fn () => Pricing::prorate($seats, 12000, day('2026-03-01'), day('2027-03-01'), day($on)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero seats' => [0, '2026-06-01'],
    'negative seats' => [-2, '2026-06-01'],
    'on the renewal date (term already over)' => [1, '2027-03-01'],
    'after the term' => [1, '2027-05-01'],
    'before the term' => [1, '2026-02-28'],
]);

test('money is parsed and formatted exactly in the currency precision', function () {
    expect(Money::parse('1250.5', 'USD'))->toBe(125050)
        ->and(Money::parse('0.001', 'KWD'))->toBe(1)
        ->and(Money::format(123456789, 'USD'))->toBe('1,234,567.89 USD')
        ->and(Money::format(1500, 'KWD'))->toBe('1.500 KWD')
        ->and(Money::toDecimal(5, 'USD'))->toBe('0.05')
        ->and(fn () => Money::parse('1.234', 'USD'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('-5', 'USD'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::decimals('XYZ'))->toThrow(InvalidArgumentException::class);
});
