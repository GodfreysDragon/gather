<?php

namespace App\Rules;

use App\Models\Venue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An event's capacity may not exceed the capacity of the venue it is held in.
 */
class FitsVenue implements ValidationRule
{
    public function __construct(private mixed $venueId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $venue = is_numeric($this->venueId) ? Venue::find($this->venueId) : null;

        if ($venue !== null && (int) $value > $venue->capacity) {
            $fail("The :attribute may not exceed the venue's capacity of {$venue->capacity}.");
        }
    }
}
