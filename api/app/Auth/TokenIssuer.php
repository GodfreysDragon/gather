<?php

namespace App\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues and rotates token pairs: a short-lived Sanctum access token for API calls,
 * and a long-lived, single-use refresh token for getting the next pair.
 *
 * Refresh tokens rotate: using one marks it used and returns a new pair in the same
 * family. Presenting a used token again means it was copied (or the client is buggy),
 * so the whole family is revoked and its owner has to log in again.
 */
class TokenIssuer
{
    /**
     * @return array{token: string, token_type: string, expires_at: ?string, refresh_token: string, refresh_token_expires_at: string}
     */
    public function issue(User $user, string $name, ?string $family = null): array
    {
        $minutes = config('sanctum.expiration');
        $access = $user->createToken($name, expiresAt: $minutes ? now()->addMinutes($minutes) : null);

        $plain = Str::random(64);
        $refresh = $user->refreshTokens()->create([
            'family' => $family ?? (string) Str::uuid(),
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'access_token_id' => $access->accessToken->getKey(),
            'expires_at' => now()->addMinutes((int) config('sanctum.refresh_expiration')),
        ]);

        return [
            'token' => $access->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $access->accessToken->expires_at?->utc()->toIso8601ZuluString(),
            'refresh_token' => $plain,
            'refresh_token_expires_at' => $refresh->expires_at->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * Exchanges a refresh token for a new pair.
     *
     * @return array{user: User, tokens: array{token: string, token_type: string, expires_at: ?string, refresh_token: string, refresh_token_expires_at: string}}
     *
     * @throws AuthenticationException when the token is unknown, expired or already used
     */
    public function refresh(string $plain): array
    {
        $outcome = DB::transaction(function () use ($plain) {
            $refresh = RefreshToken::query()
                ->where('token_hash', hash('sha256', $plain))
                ->lockForUpdate()
                ->first();

            if ($refresh === null || $refresh->isExpired()) {
                return null;
            }

            if ($refresh->used_at !== null) {
                // A rotated-out token came back: treat it as stolen.
                $this->revokeFamily($refresh->family);

                return null;
            }

            $refresh->update(['used_at' => now()]);
            // The access token it was issued with is replaced by the new one.
            PersonalAccessToken::query()->whereKey($refresh->access_token_id)->delete();

            return [
                'user' => $refresh->user,
                'tokens' => $this->issue($refresh->user, $refresh->name, $refresh->family),
            ];
        });

        // Thrown outside the transaction, so a revoked family stays revoked.
        return $outcome ?? throw new AuthenticationException('The refresh token is invalid or has expired.');
    }

    /**
     * Revokes everything descended from the login that issued this access token.
     */
    public function revokeFamilyOf(PersonalAccessToken $accessToken): void
    {
        $family = RefreshToken::query()->where('access_token_id', $accessToken->getKey())->value('family');

        $family === null ? $accessToken->delete() : $this->revokeFamily($family);
    }

    private function revokeFamily(string $family): void
    {
        $tokens = RefreshToken::query()->where('family', $family);

        PersonalAccessToken::query()->whereIn('id', (clone $tokens)->whereNotNull('access_token_id')->select('access_token_id'))->delete();
        $tokens->delete();
    }
}
