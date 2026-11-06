<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Subscription price calculations. All amounts are integers in minor units.
 *
 * Pricing model: every seat (user) has a price per month.
 * - Setup fee: paid once per seat = seat price × months × seats. For the seats a
 *   subscription starts with, months = 12; for seats added during a term, months = the
 *   term's remaining months, the month already started counting in full.
 * - Monthly fee: seat price × the term's seats, billed at the start of every month.
 *
 * Term convention: a term covers the half-open date range [starts_on, ends_on) and is
 * split into twelve monthly periods counted from starts_on (see periodBounds()).
 *
 * Rounding policy: fees are exact integer products. A percentage discount is computed
 * as an exact fraction and rounded once, half up, to the currency's minor unit.
 */
final class Pricing
{
    public const MonthsPerTerm = 12;

    public const MaxDiscountBasisPoints = 10_000;

    /**
     * Setup Fee = Seat Price × Seats × Months.
     */
    public static function setupFee(int $seatPrice, int $seats, int $months): int
    {
        if ($seatPrice < 0 || $seats < 0 || $months < 0 || $months > self::MonthsPerTerm) {
            throw new InvalidArgumentException('The seat price, seats and months must be within range.');
        }

        return BigInteger::of($seatPrice)->multipliedBy($seats)->multipliedBy($months)->toInt();
    }

    /**
     * Monthly Fee = Seat Price × Seats.
     */
    public static function monthlyFee(int $seatPrice, int $seats): int
    {
        if ($seatPrice < 0 || $seats < 0) {
            throw new InvalidArgumentException('Prices and seats cannot be negative.');
        }

        return BigInteger::of($seatPrice)->multipliedBy($seats)->toInt();
    }

    /**
     * The discount on an amount: amount × basis points / 10,000, rounded once, half up.
     */
    public static function discount(int $amount, int $basisPoints): int
    {
        if ($amount < 0 || $basisPoints < 0 || $basisPoints > self::MaxDiscountBasisPoints) {
            throw new InvalidArgumentException('The discount must be between 0% and 100%.');
        }

        return BigRational::of($amount)
            ->multipliedBy($basisPoints)
            ->dividedBy(self::MaxDiscountBasisPoints)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * The validation pattern of a discount percentage entered by an admin: 0 to 100 with at
     * most two decimal places (the precision of basis points).
     */
    public const DiscountPattern = '/^\d{1,3}(\.\d{1,2})?$/';

    /**
     * Convert a percentage entered by an admin (e.g. "12.5") to basis points (1250).
     */
    public static function percentToBasisPoints(?string $percent): int
    {
        $percent = trim((string) $percent);

        if ($percent === '') {
            return 0;
        }

        if (preg_match(self::DiscountPattern, $percent) !== 1) {
            throw new InvalidArgumentException('The discount must be between 0% and 100%.');
        }

        $basisPoints = BigDecimal::of($percent)->multipliedBy(100)->toScale(0)->toInt();

        if ($basisPoints > self::MaxDiscountBasisPoints) {
            throw new InvalidArgumentException('The discount must be between 0% and 100%.');
        }

        return $basisPoints;
    }

    /**
     * The end (renewal date, exclusive) of an annual term starting on the given date.
     *
     * Adds one calendar year without overflow: a term starting on 29 February ends on
     * 28 February of the following year.
     */
    public static function annualTermEnd(CarbonImmutable $startsOn): CarbonImmutable
    {
        return self::day($startsOn)->addYearNoOverflow();
    }

    /**
     * The half-open range [start, end) of a term's monthly period (0 to 11).
     *
     * Each boundary is counted from the term start, never chained, so a term starting on
     * 31 January has periods starting on 28 February, 31 March, 30 April, and so on. The
     * last period ends on the term's renewal date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function periodBounds(CarbonImmutable $termStartsOn, int $index): array
    {
        if ($index < 0 || $index >= self::MonthsPerTerm) {
            throw new InvalidArgumentException('A term has twelve monthly periods.');
        }

        $start = self::day($termStartsOn);

        return [$start->addMonthsNoOverflow($index), $start->addMonthsNoOverflow($index + 1)];
    }

    /**
     * The index (0 to 11) of the monthly period that contains the given day.
     */
    public static function periodIndex(CarbonImmutable $termStartsOn, CarbonImmutable $on): int
    {
        $start = self::day($termStartsOn);
        $date = self::day($on);

        if ($date->lessThan($start) || $date->greaterThanOrEqualTo(self::annualTermEnd($start))) {
            throw new InvalidArgumentException('The date is outside the term.');
        }

        $index = min(self::MonthsPerTerm - 1, ($date->year - $start->year) * 12 + $date->month - $start->month);

        while ($index > 0 && $start->addMonthsNoOverflow($index)->greaterThan($date)) {
            $index--;
        }

        return $index;
    }

    /**
     * Months left in the term on the given day, the month already started included: 12 on
     * the first month, 1 on the last.
     */
    public static function remainingMonths(CarbonImmutable $termStartsOn, CarbonImmutable $on): int
    {
        return self::MonthsPerTerm - self::periodIndex($termStartsOn, $on);
    }

    /**
     * The setup fee of seats added during a term, for the months left on the given day.
     */
    public static function prorate(int $seats, int $seatPrice, CarbonImmutable $termStartsOn, CarbonImmutable $on): Proration
    {
        if ($seats < 1) {
            throw new InvalidArgumentException('At least one seat is required.');
        }

        $months = self::remainingMonths($termStartsOn, $on);

        return new Proration(self::setupFee($seatPrice, $seats, $months), $months, self::MonthsPerTerm);
    }

    /**
     * The calendar date as UTC midnight, so day counts never depend on DST or time of day.
     */
    public static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->toDateString(), 'UTC') ?: throw new InvalidArgumentException('Invalid date.');
    }
}
