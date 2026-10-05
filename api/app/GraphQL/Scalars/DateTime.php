<?php

namespace App\GraphQL\Scalars;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Nuwave\Lighthouse\Schema\Types\Scalars\DateScalar;

/**
 * ISO 8601 instants, serialized in UTC ("2026-11-01T18:00:00Z") exactly like the
 * REST API. Any offset is accepted on input ("2026-11-01T20:00:00+02:00").
 */
class DateTime extends DateScalar
{
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    protected function format(Carbon $carbon): string
    {
        return $carbon->utc()->toIso8601ZuluString();
    }

    protected function parse(string $value): Carbon
    {
        if (preg_match(self::ISO_8601, $value) !== 1) {
            throw new InvalidArgumentException("Expected an ISO 8601 date-time with offset, got \"{$value}\".");
        }

        return Carbon::parse($value)->utc();
    }
}
