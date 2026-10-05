<?php

namespace App\Exceptions;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use RuntimeException;

/**
 * A business-rule violation. Transport-agnostic: the REST layer renders it as an
 * RFC 9457 problem document, the GraphQL layer as an error with `extensions.code`.
 */
abstract class DomainException extends RuntimeException implements ClientAware, ProvidesExtensions
{
    /**
     * Machine-readable error code, e.g. "event_full". Used as the problem `type`
     * suffix in REST and as `extensions.code` in GraphQL.
     */
    abstract public function errorCode(): string;

    abstract public function title(): string;

    public function status(): int
    {
        return 409;
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
        return ['code' => strtoupper($this->errorCode())];
    }
}
