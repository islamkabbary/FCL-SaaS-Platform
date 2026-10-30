<?php

namespace App\Rules\Fcl;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * The ACID (Advance Cargo Information Declaration number) of an Egyptian import, checked
 * leniently until its official format is confirmed (BD-A02, Q-A01).
 *
 * An ACID is always a string, never a number: leading zeros matter and the length may
 * change. Only empty and non-digit values are refused. A length other than the ones seen
 * so far is a warning for the user, not an error. A well-formed ACID is not a verified
 * one: nothing here checks it against NAFEZA or any other external service (BD-A03).
 *
 * Replace this one class when the official format is confirmed.
 */
final class Acid implements ValidationRule
{
    /**
     * Lengths reported so far (19 digits per the research brief, 16 per the owner's
     * recollection). Neither is confirmed by an official source.
     */
    public const ExpectedLengths = [16, 19];

    /**
     * Arabic-Indic and Extended Arabic-Indic digits, as ASCII digits.
     */
    public const AsciiDigits = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Without whitespace (spaces, tabs, non-breaking spaces) and the invisible format
     * characters that come with pasted text (direction marks, zero-width spaces, byte
     * order marks), with fullwidth and Arabic-Indic digits written as ASCII digits.
     */
    public static function normalize(string $value): string
    {
        $value = (string) preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $value);

        if (class_exists(Normalizer::class)) {
            $value = (string) Normalizer::normalize($value, Normalizer::FORM_KC);
        }

        return strtr($value, self::AsciiDigits);
    }

    /**
     * Digits only, at least one, after normalisation.
     */
    public static function isWellFormed(string $normalized): bool
    {
        return preg_match('/^[0-9]+\z/', $normalized) === 1;
    }

    /**
     * A warning to show when a well-formed ACID has an unexpected length, else null.
     */
    public static function lengthWarning(string $normalized): ?string
    {
        if (! self::isWellFormed($normalized) || in_array(strlen($normalized), self::ExpectedLengths, true)) {
            return null;
        }

        return __('This ACID has :count digits; ACIDs usually have 16 or 19. Check it against the shipping documents.', [
            'count' => strlen($normalized),
        ]);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isWellFormed(self::normalize($value))) {
            $fail(__('The :attribute must contain digits only.'));
        }
    }
}
