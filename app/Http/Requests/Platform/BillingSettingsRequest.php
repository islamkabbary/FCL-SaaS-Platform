<?php

namespace App\Http\Requests\Platform;

use App\Billing\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Sets the default price per user and month. The price is entered as a decimal amount
 * in the chosen currency and stored in minor units.
 */
class BillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform')?->can('manage-billing') === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currency = $this->currency();

        return [
            'currency' => ['required', Rule::in(Money::currencies())],
            'seat_price' => ['required', 'string', ...($currency !== null ? ['regex:'.Money::pattern($currency)] : [])],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $currency = $this->currency();

            if ($currency !== null && $validator->errors()->isEmpty() && $this->seatPrice() > config('billing.max_price_minor')) {
                $validator->errors()->add('seat_price', __('The amount is too large.'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'seat_price.regex' => __('Enter a non-negative amount with at most :decimals decimal places.', ['decimals' => $this->currency() !== null ? Money::decimals($this->currency()) : 0]),
        ];
    }

    /**
     * The validated price in minor units.
     */
    public function seatPrice(): int
    {
        return Money::parse((string) $this->input('seat_price'), (string) $this->currency());
    }

    /**
     * The supported currency being chosen, or null.
     */
    public function currency(): ?string
    {
        $currency = $this->input('currency');

        return is_string($currency) && in_array($currency, Money::currencies(), true) ? $currency : null;
    }
}
