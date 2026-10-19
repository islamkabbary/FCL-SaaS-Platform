<?php

namespace App\Http\Requests\Platform;

use App\Billing\Money;
use App\Models\SubscriptionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates or edits a plan. Prices are entered as decimal amounts in the plan currency
 * and stored in minor units; the currency can only be chosen when creating the plan.
 */
class PlanRequest extends FormRequest
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
        $plan = $this->route('plan');
        $currency = $this->currency();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique(SubscriptionPlan::class, 'name')->ignore($plan instanceof SubscriptionPlan ? $plan->id : null)],
            'description' => ['nullable', 'string', 'max:1000'],
            'currency' => $plan instanceof SubscriptionPlan ? ['prohibited'] : ['required', Rule::in(Money::currencies())],
            'annual_base_fee' => ['required', 'string', ...($currency !== null ? ['regex:'.Money::pattern($currency)] : [])],
            'annual_seat_price' => ['required', 'string', ...($currency !== null ? ['regex:'.Money::pattern($currency)] : [])],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $currency = $this->currency();

            if ($currency === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (['annual_base_fee', 'annual_seat_price'] as $field) {
                if (Money::parse((string) $this->input($field), $currency) > config('billing.max_price_minor')) {
                    $validator->errors()->add($field, __('The amount is too large.'));
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'annual_base_fee.regex' => __('Enter a non-negative amount with at most :decimals decimal places.', ['decimals' => $this->currency() !== null ? Money::decimals($this->currency()) : 0]),
            'annual_seat_price.regex' => __('Enter a non-negative amount with at most :decimals decimal places.', ['decimals' => $this->currency() !== null ? Money::decimals($this->currency()) : 0]),
            'currency.prohibited' => __('The currency of an existing plan cannot be changed.'),
        ];
    }

    /**
     * Validated plan attributes with prices converted to minor units.
     *
     * @return array{name: string, description: ?string, currency: string, annual_base_fee: int, annual_seat_price: int}
     */
    public function plan(): array
    {
        $currency = (string) $this->currency();

        return [
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'currency' => $currency,
            'annual_base_fee' => Money::parse((string) $this->input('annual_base_fee'), $currency),
            'annual_seat_price' => Money::parse((string) $this->input('annual_seat_price'), $currency),
        ];
    }

    /**
     * The plan currency: the existing plan's, or the supported one being chosen.
     */
    protected function currency(): ?string
    {
        $plan = $this->route('plan');

        if ($plan instanceof SubscriptionPlan) {
            return $plan->currency;
        }

        $currency = $this->input('currency');

        return is_string($currency) && in_array($currency, Money::currencies(), true) ? $currency : null;
    }
}
