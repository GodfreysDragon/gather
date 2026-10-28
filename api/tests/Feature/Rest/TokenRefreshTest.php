<?php

namespace Tests\Feature\Rest;

use App\Auth\TokenIssuer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function login(): array
    {
        User::factory()->create(['email' => 'ada@example.com']);

        return $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertOk()
            ->json();
    }

    private function assertTokenWorks(string $token, bool $works = true): void
    {
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"]);
        $works ? $response->assertOk() : $response->assertUnauthorized();
    }

    public function test_a_refresh_token_buys_a_new_pair_and_retires_the_old_one(): void
    {
        $first = $this->login();

        $this->travel(2)->hours();
        $this->assertTokenWorks($first['token'], works: false);

        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->json();

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertTokenWorks($second['token']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_reusing_a_refresh_token_revokes_the_whole_login(): void
    {
        $first = $this->login();
        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertOk()->json();

        // Someone replays the old token later, e.g. after copying it.
        $this->travel(TokenIssuer::REUSE_GRACE_SECONDS + 1)->seconds();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertUnauthorized();

        $this->assertTokenWorks($second['token'], works: false);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])->assertUnauthorized();
    }

    public function test_unknown_and_expired_refresh_tokens_are_rejected(): void
    {
        $tokens = $this->login();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'not-a-token'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'The refresh token is invalid or has expired.');

        $this->travel(31)->days();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_logging_out_also_revokes_the_refresh_token(): void
    {
        $tokens = $this->login();

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$tokens['token']}"])->assertNoContent();

        $this->assertDatabaseCount('refresh_tokens', 0);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_each_login_is_its_own_family(): void
    {
        $phone = $this->login();
        $laptop = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])->json();

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$phone['token']}"])->assertNoContent();

        $this->assertTokenWorks($laptop['token']);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $laptop['refresh_token']])->assertOk();
    }

    public function test_refresh_limits_are_per_token_so_people_sharing_an_ip_do_not_collide(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'stolen-guess'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'stolen-guess'])->assertTooManyRequests();

        // A colleague behind the same IP still refreshes normally.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $this->login()['refresh_token']])->assertOk();
    }

    public function test_a_duplicate_refresh_seconds_later_is_refused_without_ending_the_login(): void
    {
        $first = $this->login();
        // Two tabs, or a retry after a lost response, present the same token at once.
        $winner = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertOk()->json();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertUnauthorized();

        $this->assertTokenWorks($winner['token']);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $winner['refresh_token']])->assertOk();
    }
}
