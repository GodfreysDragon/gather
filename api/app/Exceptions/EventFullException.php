<?php

namespace App\Exceptions;

use App\Models\Event;

class EventFullException extends DomainException
{
    public function __construct(Event $event)
    {
        parent::__construct("\"{$event->title}\" has no spots left.");
    }

    public function errorCode(): string
    {
        return 'event_full';
    }

    public function title(): string
    {
        return 'Event is full';
    }
}
