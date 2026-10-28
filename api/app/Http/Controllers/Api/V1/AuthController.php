<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\TokenIssuer;
use App\Exceptions\EmailNotVerifiedException;
use App\Exceptions\InvalidVerificationLinkException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AccountAlreadyExists;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Stateless bearer-token auth: short-lived Sanctum access tokens plus rotating refresh
 * tokens. No cookies or sessions, so any client on any origin (SPA, mobile app, CLI)
 * uses the API the same way.
 *
 * Nothing here reveals whether an e-mail address has an account: registration and
 * resending answer the same either way, and a failed login looks the same for an
 * unknown address as for a wrong password.
 */
class AuthController extends Controller
{
    /**
     * A bcrypt hash (cost 12, like real passwords) of a random string nobody knows.
     * Checking against it when the e-mail is unknown makes a failed login take as long
     * as one with a wrong password, so response times don't reveal which accounts exist.
     */
    private const TIMING_HASH = '$2y$12$JNe1yNreZnk5gtJMxbiD1.8i7l8HRehwha1vJQDybXdHdfT8NjfCe';

    private const CHECK_INBOX = 'Check your inbox: we\'ve sent you an e-mail to confirm your address.';

    public function __construct(private TokenIssuer $tokens) {}

    public function register(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $existing = User::where('email', $data['email'])->first();

        if ($existing === null) {
            try {
                User::create($data)->notify(new VerifyEmailAddress);

                return $this->accepted();
            } catch (UniqueConstraintViolationException) {
                // A concurrent registration for the same address got there first.
                $existing = User::where('email', $data['email'])->firstOrFail();
            }
        }

        if ($existing->hasVerifiedEmail()) {
            // Hash anyway, so this path takes as long as creating an account.
            Hash::make($data['password']);
            $existing->notify(new AccountAlreadyExists);
        } else {
            // Until the address is confirmed the account belongs to nobody, so the latest
            // registration wins. Keeping the first password would let someone register
            // another person's address in advance and still log in after they confirm it.
            $existing->forceFill(['name' => $data['name'], 'password' => $data['password']])->save();
            $existing->notify(new VerifyEmailAddress);
        }

        return $this->accepted();
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

        // Only the address's owner can get this far, so saying so reveals nothing new.
        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        return $this->tokenResponse($request, $user, $this->tokens->issue($user, $data['device_name'] ?? 'web'), Response::HTTP_OK);
    }

    /**
     * Completes registration from the link in the confirmation e-mail (route is signed),
     * and logs the user in.
     */
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $data = $request->validate(['device_name' => ['sometimes', 'string', 'max:100']]);

        $user = User::find($id);

        if ($user === null || $user->hasVerifiedEmail() || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new InvalidVerificationLinkException;
        }

        $user->markEmailAsVerified();

        return $this->tokenResponse($request, $user, $this->tokens->issue($user, $data['device_name'] ?? 'web'), Response::HTTP_OK);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);

        $data = $request->validate(['email' => ['required', 'string', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->notify(new VerifyEmailAddress);
        }

        return $this->accepted();
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:255']]);

        ['user' => $user, 'tokens' => $tokens] = $this->tokens->refresh($data['refresh_token']);

        return $this->tokenResponse($request, $user, $tokens, Response::HTTP_OK);
    }

    /**
     * Ends this login everywhere it was refreshed: the access token and its whole
     * refresh-token family are revoked.
     */
    public function logout(Request $request): Response
    {
        $this->tokens->revokeFamilyOf($request->user()->currentAccessToken());

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

    private function accepted(): JsonResponse
    {
        return response()->json(['message' => self::CHECK_INBOX], Response::HTTP_ACCEPTED);
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function tokenResponse(Request $request, User $user, array $tokens, int $status): JsonResponse
    {
        // The user isn't the request's authenticated user, so show their e-mail explicitly.
        $request->setUserResolver(fn () => $user);

        return response()->json([...$tokens, 'user' => new UserResource($user)], $status);
    }
}
