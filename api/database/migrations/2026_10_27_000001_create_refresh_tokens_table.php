<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Long-lived refresh tokens, paired with the short-lived Sanctum access tokens.
     * Each refresh rotates the pair; all tokens descended from one login share a
     * `family`, so a stolen, already-used refresh token can revoke the whole chain.
     */
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('family')->index();
            $table->string('name');
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
