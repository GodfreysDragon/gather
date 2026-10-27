<?php

namespace App\Exceptions;

/**
 * The link's signature was valid but it no longer matches the account: the address
 * changed, or it has already been confirmed. Either way the link can't be used.
 */
class InvalidVerificationLinkException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This confirmation link has already been used or is no longer valid. Try logging in.');
    }

    public function status(): int
    {
        return 410;
    }

    public function errorCode(): string
    {
        return 'verification_link_invalid';
    }

    public function title(): string
    {
        return 'Confirmation link no longer valid';
    }
}
