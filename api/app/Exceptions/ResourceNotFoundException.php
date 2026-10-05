<?php

namespace App\Exceptions;

class ResourceNotFoundException extends DomainException
{
    public function __construct(string $resource)
    {
        parent::__construct("{$resource} not found.");
    }

    public function status(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function title(): string
    {
        return 'Not found';
    }
}
