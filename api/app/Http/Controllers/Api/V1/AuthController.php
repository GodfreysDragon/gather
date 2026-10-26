<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Stateless bearer-token auth (Sanctum personal access tokens). No cookies or
 * sessions, so any client on any origin (SPA, mobile app, CLI) uses the API the same way.
 */
class AuthController extends Controller
{
    /**
     * A bcrypt hash (cost 12, like real passwords) of a random string nobody knows.
     * Checking against it when the e-mail is unknown makes a failed login take as long
     * as one with a wrong password, so response times don't reveal which accounts exist.
     */
    private const TIMING_HASH = '$2y$12$JNe1yNreZnk5gtJMxbiD1.8i7l8HRehwha1vJQDybXdHdfT8NjfCe';

    public function register(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::create($data);

        return $this->tokenResponse($request, $user, $data['device_name'] ?? 'web', Response::HTTP_CREATED);
    }

    public function login(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);

        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        $passwordMatches = Hash::check($data['password'], $user->password ?? self::TIMING_HASH);

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return $this->tokenResponse($request, $user, $data['device_name'] ?? 'web', Response::HTTP_OK);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * E-mail addresses are case-insensitive in practice, but SQLite compares strings
     * case-sensitively, so "Demo@…" would otherwise be a second account.
     */
    private function normaliseEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
    }

    private function tokenResponse(Request $request, User $user, string $deviceName, int $status): JsonResponse
    {
        $minutes = config('sanctum.expiration');
        $token = $user->createToken($deviceName, expiresAt: $minutes ? now()->addMinutes($minutes) : null);

        // The new user isn't the request's authenticated user yet, so show their e-mail explicitly.
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->utc()->toIso8601ZuluString(),
            'user' => new UserResource($user),
        ], $status);
    }
}
