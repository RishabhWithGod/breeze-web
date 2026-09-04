<?php

namespace App\Rules;

use App\Support\UsPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US phone number, however it was typed.
 *
 * Spaces, dashes, brackets, dots and a leading +1 are all accepted on the way
 * in — people paste numbers from everywhere — and the value is normalised
 * before it is stored. What is rejected is a number that could not ring: the
 * wrong number of digits, or an area or exchange code the numbering plan does
 * not issue.
 */
class UsPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! is_string($value) || ! UsPhone::valid($value)) {
            $fail('Enter a US phone number, like (555) 123-4567.');
        }
    }
}
