<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function acceptsRegistrations(): bool
    {
        return $this === self::Published;
    }
}
