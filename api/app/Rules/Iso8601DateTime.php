<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An ISO 8601 date-time with an explicit offset ("2026-11-01T18:00:00Z" or
 * "…T20:00:00+02:00"). Laravel's `date` rule alone also accepts strings like
 * "next friday" and offset-less times, which the contract doesn't allow.
 */
class Iso8601DateTime implements ValidationRule
{
    public const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute must be an ISO 8601 date-time with a time zone offset, e.g. 2026-11-01T18:00:00Z.');
        }
    }
}
