<?php

namespace App\Rules\Fcl;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Normalizer;

/**
 * ISO 6346 container number: a three-letter owner code, a category identifier (U freight
 * container, J detachable freight-container equipment, Z trailer or chassis), a six-digit
 * serial number and a check digit, for example "CSQU 305438 3".
 *
 * A valid number only means the number is well-formed. It says nothing about whether the
 * container exists or belongs to the shipment; nothing here is an external verification.
 *
 * As a validation rule it refuses malformed input. A wrong check digit is refused too,
 * unless the rule is built with allowWrongCheckDigit() for a deliberate, audited override.
 */
final class ContainerNumber implements ValidationRule
{
    /**
     * Owner code, category identifier, serial number and check digit.
     */
    public const Pattern = '/^[A-Z]{3}[UJZ]\d{6}\d\z/';

    /**
     * The numeric value of each letter. Multiples of 11 (11, 22, 33) are skipped.
     */
    private const LetterValues = [
        'A' => 10, 'B' => 12, 'C' => 13, 'D' => 14, 'E' => 15, 'F' => 16, 'G' => 17, 'H' => 18, 'I' => 19,
        'J' => 20, 'K' => 21, 'L' => 23, 'M' => 24, 'N' => 25, 'O' => 26, 'P' => 27, 'Q' => 28, 'R' => 29,
        'S' => 30, 'T' => 31, 'U' => 32, 'V' => 34, 'W' => 35, 'X' => 36, 'Y' => 37, 'Z' => 38,
    ];

    public function __construct(private readonly bool $allowWrongCheckDigit = false) {}

    /**
     * The rule for a number whose check digit the user has knowingly overridden.
     */
    public static function allowWrongCheckDigit(): self
    {
        return new self(allowWrongCheckDigit: true);
    }

    /**
     * Upper case, without the spaces and dashes often used to group the parts and without
     * the invisible format characters that come with pasted text; fullwidth letters and
     * digits are written as ASCII.
     */
    public static function normalize(string $value): string
    {
        $value = (string) preg_replace('/[\s\p{Z}\p{Cf}\p{Pd}\x{2212}]+/u', '', $value);

        if (class_exists(Normalizer::class)) {
            $value = (string) Normalizer::normalize($value, Normalizer::FORM_KC);
        }

        return strtoupper($value);
    }

    /**
     * Whether a normalised value has the shape of a container number, check digit aside.
     */
    public static function isWellFormed(string $normalized): bool
    {
        return preg_match(self::Pattern, $normalized) === 1;
    }

    /**
     * The check digit of the first ten characters (owner code, category and serial).
     */
    public static function checkDigit(string $firstTen): int
    {
        if (preg_match('/^[A-Z]{4}\d{6}\z/', $firstTen) !== 1) {
            throw new InvalidArgumentException('A check digit is computed from four letters and six digits.');
        }

        $sum = 0;

        foreach (str_split($firstTen) as $position => $character) {
            $value = ctype_digit($character) ? (int) $character : self::LetterValues[$character];
            $sum += $value * (2 ** $position);
        }

        // A remainder of 10 is written as 0.
        return $sum % 11 % 10;
    }

    /**
     * Whether the check digit of a normalised, well-formed number is correct.
     */
    public static function hasValidCheckDigit(string $normalized): bool
    {
        return self::isWellFormed($normalized)
            && self::checkDigit(substr($normalized, 0, 10)) === (int) $normalized[10];
    }

    /**
     * Well-formed with a correct check digit, after normalisation.
     */
    public static function isValid(string $value): bool
    {
        return self::hasValidCheckDigit(self::normalize($value));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isWellFormed($normalized = self::normalize($value))) {
            $fail(__('The :attribute must be a container number: four letters ending in U, J or Z, then seven digits.'));

            return;
        }

        if (! $this->allowWrongCheckDigit && ! self::hasValidCheckDigit($normalized)) {
            $fail(__('The check digit of this container number is wrong. Check the number, or confirm it as written.'));
        }
    }
}
