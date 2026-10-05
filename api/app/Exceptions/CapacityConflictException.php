<?php

namespace App\Exceptions;

class CapacityConflictException extends DomainException
{
    public function __construct(int $requested, int $registered)
    {
        parent::__construct("Capacity cannot be lowered to {$requested}: {$registered} people are already registered.");
    }

    public function errorCode(): string
    {
        return 'capacity_conflict';
    }

    public function title(): string
    {
        return 'Capacity below current registrations';
    }
}
