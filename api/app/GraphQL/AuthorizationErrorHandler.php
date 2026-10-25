<?php

namespace App\GraphQL;

use Closure;
use GraphQL\Error\Error;
use Illuminate\Auth\Access\AuthorizationException;
use Nuwave\Lighthouse\Execution\ErrorHandler;

/**
 * Gives policy denials a machine-readable `extensions.code`, like the domain errors
 * have: FORBIDDEN, or NOT_FOUND for a denyAsNotFound() response. Clients can then
 * map them to the same status the REST API would use, without matching messages.
 */
class AuthorizationErrorHandler implements ErrorHandler
{
    public function __invoke(?Error $error, Closure $next): ?array
    {
        $denied = $error?->getPrevious();

        if (! $denied instanceof AuthorizationException) {
            return $next($error);
        }

        $code = $denied->status() === 404 ? 'NOT_FOUND' : 'FORBIDDEN';

        return $next(new Error(
            $error->getMessage(),
            $error->getNodes(),
            $error->getSource(),
            $error->getPositions(),
            $error->getPath(),
            new PolicyDenied($error->getMessage(), $code),
        ));
    }
}
