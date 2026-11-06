<?php

use App\Billing\Money;
use App\Billing\Pricing;
use Carbon\CarbonImmutable;

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('the setup fee is the seat price times twelve months times the seats', function () {
    // The owner's example: 100 users at 18.00 USD → 18 × 12 × 100 = 21,600.00 USD.
    expect(Pricing::setupFee(1800, 100, 12))->toBe(2_160_000)
        ->and(Pricing::monthlyFee(1800, 100))->toBe(180_000);
});

test('users added in the seventh month pay a setup fee for the six remaining months', function () {
    // Term from 1 January; on 10 July the seventh month has started: 12 − 6 = 6 months.
    $proration = Pricing::prorate(20, 1800, day('2026-01-01'), day('2026-07-10'));

    expect($proration->remainingMonths)->toBe(6)
        ->and($proration->totalMonths)->toBe(12)
        ->and($proration->amount)->toBe(216_000) // 18 × 6 × 20 = 2,160.00 USD
        ->and(Pricing::monthlyFee(1800, 120))->toBe(216_000);
});

test('the month already started counts in full', function (string $on, int $months) {
    expect(Pricing::remainingMonths(day('2026-01-15'), day($on)))->toBe($months);
})->with([
    'first day of the term' => ['2026-01-15', 12],
    'last day of the first month' => ['2026-02-14', 12],
    'first day of the second month' => ['2026-02-15', 11],
    'middle of the last month' => ['2027-01-01', 1],
    'last day of the term' => ['2027-01-14', 1],
]);

test('large values stay exact and negative inputs are rejected', function () {
    expect(Pricing::setupFee(100_000_000_000, 10000, 12))->toBe(12_000_000_000_000_000)
        ->and(fn () => Pricing::setupFee(-1, 1, 12))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pricing::setupFee(100, 1, 13))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pricing::monthlyFee(100, -1))->toThrow(InvalidArgumentException::class);
});

test('an annual term ends one calendar year later and handles leap days and year ends', function (string $start, string $end) {
    expect(Pricing::annualTermEnd(day($start))->toDateString())->toBe($end);
})->with([
    'ordinary year' => ['2025-03-15', '2026-03-15'],
    'starting on 29 February' => ['2028-02-29', '2029-02-28'],
    'starting on 31 December' => ['2026-12-31', '2027-12-31'],
]);

test('monthly periods are counted from the term start without drifting at month ends', function () {
    $start = day('2026-01-31');

    expect(Pricing::periodBounds($start, 0)[0]->toDateString())->toBe('2026-01-31')
        ->and(Pricing::periodBounds($start, 0)[1]->toDateString())->toBe('2026-02-28')
        ->and(Pricing::periodBounds($start, 2)[0]->toDateString())->toBe('2026-03-31')
        ->and(Pricing::periodBounds($start, 11)[1]->toDateString())->toBe(Pricing::annualTermEnd($start)->toDateString())
        ->and(Pricing::periodIndex($start, day('2026-02-27')))->toBe(0)
        ->and(Pricing::periodIndex($start, day('2026-02-28')))->toBe(1)
        ->and(Pricing::periodIndex($start, day('2027-01-30')))->toBe(11);
});

test('a term starting on 29 February has twelve periods ending on its renewal date', function () {
    $start = day('2028-02-29');

    expect(Pricing::periodBounds($start, 11)[1]->toDateString())->toBe('2029-02-28')
        ->and(Pricing::periodIndex($start, day('2029-02-27')))->toBe(11);
});

test('setup fees are rejected for zero seats and for dates outside the term', function (int $seats, string $on) {
    expect(fn () => Pricing::prorate($seats, 1800, day('2026-03-01'), day($on)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero seats' => [0, '2026-06-01'],
    'on the renewal date (term already over)' => [1, '2027-03-01'],
    'before the term' => [1, '2026-02-28'],
]);

test('a discount is a percentage of the amount, rounded half up once', function (int $amount, int $basisPoints, int $expected) {
    expect(Pricing::discount($amount, $basisPoints))->toBe($expected);
})->with([
    'ten percent' => [2_160_000, 1000, 216_000],
    'fractional percentage' => [10_000, 1250, 1250],
    'just below half rounds down' => [1, 4999, 0],
    'exact half rounds up' => [1, 5000, 1],
    'full discount' => [1800, 10_000, 1800],
    'no discount' => [1800, 0, 0],
]);

test('a discount percentage is converted to basis points and bounded', function () {
    expect(Pricing::percentToBasisPoints('12.5'))->toBe(1250)
        ->and(Pricing::percentToBasisPoints('100'))->toBe(10_000)
        ->and(Pricing::percentToBasisPoints(''))->toBe(0)
        ->and(Pricing::percentToBasisPoints(null))->toBe(0)
        ->and(fn () => Pricing::percentToBasisPoints('100.01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pricing::percentToBasisPoints('1.234'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pricing::percentToBasisPoints('-5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pricing::discount(100, 10_001))->toThrow(InvalidArgumentException::class);
});

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
