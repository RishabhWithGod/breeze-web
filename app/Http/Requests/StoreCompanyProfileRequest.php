<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyProfileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'business_address' => ['required', 'string', 'max:1000'],
            'primary_contact' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            // Correcting a company can also take its logo away.
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // A phone number is judged by its digits, not by how it was punctuated.
        $validator->after(function (Validator $validator) {
            $digits = preg_replace('/\D/', '', (string) $this->input('phone'));

            if (! $validator->errors()->has('phone') && (strlen($digits) < 10 || strlen($digits) > 15)) {
                $validator->errors()->add('phone', 'Enter a valid phone number.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Company name is required.',
            'business_address.required' => 'Business address is required.',
            'primary_contact.required' => 'Primary contact is required.',
            'phone.required' => 'Phone is required.',
            'email.required' => 'Email is required.',
            'timezone.required' => 'Choose a time zone.',
            'timezone.in' => 'Choose a time zone from the list.',
            'logo.image' => 'The logo must be an image.',
            'logo.mimes' => 'The logo must be a PNG, JPG or WebP file.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ];
    }
}
