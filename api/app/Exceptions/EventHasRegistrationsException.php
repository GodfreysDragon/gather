<?php

namespace App\Exceptions;

class EventHasRegistrationsException extends DomainException
{
    public function __construct(int $registered, string $instead = 'deleting it')
    {
        parent::__construct("This event has {$registered} registration(s). Cancel it instead of {$instead}.");
    }

    public function errorCode(): string
    {
        return 'event_has_registrations';
    }

    public function title(): string
    {
        return 'Event has registrations';
    }
}
