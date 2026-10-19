<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Monetary amounts are integers in the currency's minor unit (e.g. cents), always
 * paired with an ISO 4217 currency code. No floating point is ever used.
 */
final class Money
{
    /**
     * Supported currency codes.
     *
     * @return list<string>
     */
    public static function currencies(): array
    {
        return array_map('strval', array_keys(config('billing.currencies')));
    }

    /**
     * Number of decimal places of the currency's minor unit.
     *
     * @return int<0, max>
     */
    public static function decimals(string $currency): int
    {
        $decimals = config('billing.currencies.'.strtoupper($currency));

        if (! is_int($decimals) || $decimals < 0) {
            throw new InvalidArgumentException("Unsupported currency [{$currency}].");
        }

        return $decimals;
    }

    /**
     * Convert a decimal string entered by an admin (e.g. "1250.50") to minor units.
     *
     * Rejects more decimal places than the currency has, so nothing is rounded silently.
     */
    public static function parse(string $amount, string $currency): int
    {
        $decimals = self::decimals($currency);

        if (preg_match('/^\d{1,15}(\.\d+)?$/', trim($amount)) !== 1) {
            throw new InvalidArgumentException('The amount must be a non-negative decimal number.');
        }

        try {
            return BigDecimal::of(trim($amount))->toScale($decimals)->getUnscaledValue()->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException("The amount may have at most {$decimals} decimal places.");
        }
    }

    /**
     * The validation pattern for an amount in the given currency.
     */
    public static function pattern(string $currency): string
    {
        $decimals = self::decimals($currency);

        return $decimals === 0 ? '/^\d{1,15}$/' : '/^\d{1,15}(\.\d{1,'.$decimals.'})?$/';
    }

    /**
     * Minor units as a plain decimal string, e.g. 125050 USD → "1250.50".
     */
    public static function toDecimal(int $minor, string $currency): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, self::decimals($currency));
    }

    /**
     * Human readable amount, e.g. 125050 USD → "1,250.50 USD".
     */
    public static function format(int $minor, string $currency): string
    {
        [$integral, $fraction] = array_pad(explode('.', self::toDecimal(abs($minor), $currency)), 2, null);

        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integral);

        return ($minor < 0 ? '-' : '').$grouped.($fraction !== null ? '.'.$fraction : '').' '.strtoupper($currency);
    }
}
