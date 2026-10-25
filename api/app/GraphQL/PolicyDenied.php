<?php

namespace App\GraphQL;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use RuntimeException;

/**
 * A policy denial as GraphQL reports it: the policy's message plus a stable code.
 */
class PolicyDenied extends RuntimeException implements ClientAware, ProvidesExtensions
{
    public function __construct(string $message, private string $errorCode)
    {
        parent::__construct($message);
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * @return array{code: string}
     */
    public function getExtensions(): array
    {
        return ['code' => $this->errorCode];
    }
}
