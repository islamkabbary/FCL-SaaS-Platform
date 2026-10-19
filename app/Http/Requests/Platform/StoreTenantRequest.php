<?php

namespace App\Http\Requests\Platform;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Stancl\Tenancy\Database\Models\Domain;

class StoreTenantRequest extends FormRequest
{
    /**
     * Subdomains that can never be assigned to a tenant.
     *
     * @var list<string>
     */
    public const ReservedSubdomains = [
        'admin', 'api', 'app', 'assets', 'central', 'dashboard', 'ftp', 'mail',
        'platform', 'smtp', 'static', 'status', 'support', 'www',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'subdomain' => Str::lower(trim((string) $this->input('subdomain'))),
            'admin_email' => Str::lower(trim((string) $this->input('admin_email'))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required',
                'string',
                'min:3',
                'max:40',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::notIn(self::ReservedSubdomains),
                Rule::unique(Domain::class, 'domain'),
            ],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subdomain.regex' => __('The subdomain may only contain lowercase letters, numbers and single hyphens.'),
            'subdomain.not_in' => __('This subdomain is reserved.'),
        ];
    }
}
