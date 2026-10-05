<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a weak ETag to successful GET responses and answers `If-None-Match`
 * with 304 Not Modified, so clients can revalidate instead of re-downloading.
 */
class AddETag
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $response->setEtag(hash('xxh128', (string) $response->getContent()), weak: true);
        $response->headers->addCacheControlDirective('private');
        $response->headers->addCacheControlDirective('no-cache');

        $response->isNotModified($request);

        return $response;
    }
}
