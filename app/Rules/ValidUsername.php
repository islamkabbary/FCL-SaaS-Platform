<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A username: a lowercase letter, then lowercase letters, digits, dots, hyphens or
 * underscores. "@" is excluded so a username can never look like an email address.
 */
class ValidUsername implements ValidationRule
{
    public const MinLength = 3;

    public const MaxLength = 30;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[a-z][a-z0-9._-]{'.(self::MinLength - 1).','.(self::MaxLength - 1).'}$/D', $value) !== 1) {
            $fail(__('The username must start with a letter and contain 3 to 30 lowercase letters, numbers, dots, hyphens or underscores.'));
        }
    }
}
