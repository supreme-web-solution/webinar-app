<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PersonDisplayName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = trim((string) $value);

        if ($name === '') {
            return;
        }

        if (filter_var($name, FILTER_VALIDATE_EMAIL)) {
            $fail('The :attribute must be a name, not an email address.');

            return;
        }

        if (str_contains($name, '@')) {
            $fail('The :attribute must be a name, not an email address.');

            return;
        }

        if (preg_match('/^https?:\/\//i', $name) === 1 || preg_match('/^www\./i', $name) === 1) {
            $fail('The :attribute must be a name, not a URL.');

            return;
        }

        if (preg_match('/:\/\/|\\\\/', $name) === 1) {
            $fail('The :attribute must be a plain name.');

            return;
        }

        if (preg_match('/\p{L}/u', $name) !== 1) {
            $fail('The :attribute must contain at least one letter.');

            return;
        }

        if (preg_match('/^[\p{L}\p{M}\p{N}\s\'\.\&\-]+$/u', $name) !== 1) {
            $fail('The :attribute may only contain letters, numbers, spaces, and basic punctuation.');
        }
    }
}
