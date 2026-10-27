<?php

namespace App\Exceptions;

class EmailNotVerifiedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Confirm your e-mail address first: use the link we sent you, or ask for a new one.');
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'email_not_verified';
    }

    public function title(): string
    {
        return 'E-mail address not confirmed';
    }
}
