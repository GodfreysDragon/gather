<?php

namespace App\Exceptions;

use App\Models\Event;

class RegistrationClosedException extends DomainException
{
    public function __construct(Event $event)
    {
        parent::__construct(match (true) {
            $event->starts_at->isPast() => "\"{$event->title}\" has already started.",
            default => "\"{$event->title}\" is {$event->status->value} and not accepting registrations.",
        });
    }

    public function errorCode(): string
    {
        return 'registration_closed';
    }

    public function title(): string
    {
        return 'Registration is closed';
    }
}
