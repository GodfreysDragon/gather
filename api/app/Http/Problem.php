<?php

namespace App\Http;

use App\Exceptions\DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API error as an RFC 9457 "problem details" document
 * (`application/problem+json`), so clients handle one error shape everywhere.
 */
class Problem
{
    public const CONTENT_TYPE = 'application/problem+json';

    public static function fromThrowable(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::make(422, 'Validation failed', $e->getMessage(), 'validation_failed', [
                'errors' => $e->errors(),
            ]),
            // Keep a specific reason (e.g. a rejected refresh token); the framework's default message says nothing.
            $e instanceof AuthenticationException => self::make(401, 'Unauthenticated', $e->getMessage() !== 'Unauthenticated.'
                ? $e->getMessage()
                : 'A valid bearer token is required.'),
            // Only the e-mail confirmation route is signed, so a bad signature means an expired or altered link.
            $e instanceof InvalidSignatureException => self::make(403, 'Confirmation link expired', 'This confirmation link has expired or was altered. Ask for a new one.', 'verification_link_expired'),
            $e instanceof DomainException => self::make($e->status(), $e->title(), $e->getMessage(), $e->errorCode()),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::make(500, 'Server error', config('app.debug') ? $e->getMessage() : 'Something went wrong.'),
        };
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    public static function make(int $status, string $title, ?string $detail = null, ?string $code = null, array $extensions = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(array_filter([
            'type' => $code !== null ? url('/problems/'.Str::slug($code)) : 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            ...$extensions,
        ], fn ($value) => $value !== null), $status, [...$headers, 'Content-Type' => self::CONTENT_TYPE]);
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $previous = $e->getPrevious();

        $detail = match (true) {
            $previous instanceof ModelNotFoundException => Str::headline(class_basename($previous->getModel())).' not found.',
            $status === 403 => $e->getMessage() ?: 'You are not allowed to perform this action.',
            default => $e->getMessage() ?: null,
        };

        return self::make($status, Response::$statusTexts[$status] ?? 'Error', $detail, headers: $e->getHeaders());
    }
}
