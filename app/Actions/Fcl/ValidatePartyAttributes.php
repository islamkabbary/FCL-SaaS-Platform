<?php

namespace App\Actions\Fcl;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validate and normalise the attributes of a party, for CreateParty and UpdateParty, so
 * no caller can store a party the form would have refused.
 */
class ValidatePartyAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{legal_name: string, tax_number: string|null, country_code: string|null, notes: string|null, is_active: bool}
     *
     * @throws ValidationException
     */
    public function handle(array $attributes): array
    {
        $attributes = [
            'legal_name' => $this->text($attributes['legal_name'] ?? null, collapse: true),
            'tax_number' => $this->text($attributes['tax_number'] ?? null, collapse: true),
            // ASCII upper case only: "ß" must not turn into a valid-looking "SS".
            'country_code' => is_string($code = $this->text($attributes['country_code'] ?? null)) ? strtoupper($code) : $code,
            'notes' => $this->text($attributes['notes'] ?? null),
            'is_active' => $attributes['is_active'] ?? true,
        ];

        /** @var array{legal_name: string, tax_number: string|null, country_code: string|null, notes: string|null, is_active: bool|int|string} $validated */
        $validated = Validator::make($attributes, [
            'legal_name' => ['required', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ], [
            'country_code.regex' => __('The country must be a two-letter ISO country code, for example EG.'),
        ], [
            'legal_name' => __('legal name'),
            'tax_number' => __('tax or registration number'),
            'country_code' => __('country'),
        ])->validate();

        return [...$validated, 'is_active' => (bool) $validated['is_active']];
    }

    /**
     * Trimmed text, or null when empty. Non-strings are left for the validator to refuse.
     */
    protected function text(mixed $value, bool $collapse = false): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($collapse ? (string) preg_replace('/\s+/u', ' ', $value) : $value);

        return $value === '' ? null : $value;
    }
}
