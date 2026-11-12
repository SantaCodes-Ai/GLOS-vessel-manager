<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ImoNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{7}\z/', $value)) {
            $fail('The IMO number must contain exactly seven digits.');

            return;
        }

        $sum = 0;
        for ($index = 0; $index < 6; $index++) {
            $sum += (int) $value[$index] * (7 - $index);
        }

        if ($sum % 10 !== (int) $value[6]) {
            $fail('The IMO number has an invalid check digit.');
        }
    }
}
