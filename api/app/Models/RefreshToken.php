<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use refresh token. Only its SHA-256 hash is stored, like Sanctum's
 * access tokens, so a database leak doesn't hand out working tokens.
 */
#[Fillable(['user_id', 'family', 'name', 'token_hash', 'access_token_id', 'expires_at', 'used_at'])]
#[Hidden(['token_hash'])]
class RefreshToken extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
