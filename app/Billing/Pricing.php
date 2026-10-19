<?php

namespace App\Billing;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Subscription price calculations. All amounts are integers in minor units.
 *
 * Rounding policy: annual totals are exact (integer sums and products). A prorated
 * amount is computed as an exact fraction and rounded once, half up, to the
 * currency's minor unit.
 *
 * Term convention: a term covers the half-open date range [starts_on, ends_on).
 * starts_on is the first day of service and ends_on is the renewal date, the first
 * day no longer covered.
 */
final class Pricing
{
    /**
     * Annual Subscription Total = Annual Base Fee + (Purchased Seats × Annual Price Per Seat).
     */
    public static function annualTotal(int $annualBaseFee, int $annualSeatPrice, int $seats): int
    {
        if ($annualBaseFee < 0 || $annualSeatPrice < 0 || $seats < 0) {
            throw new InvalidArgumentException('Prices and seats cannot be negative.');
        }

        return BigInteger::of($annualSeatPrice)->multipliedBy($seats)->plus($annualBaseFee)->toInt();
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
     * Number of days in the half-open range [from, to).
     */
    public static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round(self::day($from)->diffInDays(self::day($to)));
    }

    /**
     * The calendar date as UTC midnight, so day counts never depend on DST or time of day.
     */
    public static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->toDateString(), 'UTC') ?: throw new InvalidArgumentException('Invalid date.');
    }

    /**
     * Charge for additional seats from the given date until the end of the current term.
     *
     * Prorated Charge = Seats × Annual Seat Price × (Remaining Term Days / Total Term Days),
     * where the given date itself counts as a remaining day.
     */
    public static function prorate(int $seats, int $annualSeatPrice, CarbonImmutable $termStartsOn, CarbonImmutable $termEndsOn, CarbonImmutable $on): Proration
    {
        if ($seats < 1) {
            throw new InvalidArgumentException('At least one seat is required.');
        }

        if ($annualSeatPrice < 0) {
            throw new InvalidArgumentException('The seat price cannot be negative.');
        }

        $start = self::day($termStartsOn);
        $end = self::day($termEndsOn);
        $date = self::day($on);

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('The term must end after it starts.');
        }

        if ($date->lessThan($start) || $date->greaterThanOrEqualTo($end)) {
            throw new InvalidArgumentException('The date is outside the term.');
        }

        $totalDays = self::daysBetween($start, $end);
        $remainingDays = self::daysBetween($date, $end);

        $amount = BigRational::of($annualSeatPrice)
            ->multipliedBy($seats)
            ->multipliedBy($remainingDays)
            ->dividedBy($totalDays)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();

        return new Proration($amount, $remainingDays, $totalDays);
    }
}
