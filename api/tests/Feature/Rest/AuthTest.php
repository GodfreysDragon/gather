<?php

namespace Tests\Feature\Rest;

use App\Models\User;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_a_token_that_authenticates_later_requests(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_login_with_a_wrong_password_fails_without_revealing_which_field_was_wrong(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Forget the guard's cached user so the next request re-reads the token.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_protected_endpoints_return_a_401_problem_without_a_token(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['type' => 'about:blank', 'title' => 'Unauthenticated', 'status' => 401]);
    }

    public function test_auth_endpoints_are_rate_limited(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'guess'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'guess'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('status', 429);
    }

    public function test_email_addresses_are_case_insensitive(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ADA@Example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'ada@example.com');
    }

    public function test_access_tokens_expire_after_an_hour(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('expires_at', now()->addHour()->utc()->toIso8601ZuluString())
            ->assertJsonPath('refresh_token_expires_at', now()->addDays(30)->utc()->toIso8601ZuluString());
        $headers = ['Authorization' => 'Bearer '.$response->json('token')];

        $this->travel(59)->minutes();
        $this->getJson('/api/v1/me', $headers)->assertOk();

        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_an_unknown_email_fails_exactly_like_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'nope']);
        $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'nope']);

        $unknownEmail->assertUnprocessable();
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    public function test_credential_guessing_is_also_capped_per_ip_across_addresses(): void
    {
        foreach (range(1, 30) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => "user{$attempt}@example.com", 'password' => 'guess'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'one-more@example.com', 'password' => 'guess'])->assertTooManyRequests();
    }

    public function test_a_non_string_email_is_a_validation_error_not_a_crash(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => ['ada@example.com'], 'password' => 'guess'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_confirmation_links_cannot_be_signed_without_an_app_key(): void
    {
        config(['app.key' => '']);

        $this->expectException(MissingAppKeyException::class);
        URL::temporarySignedRoute('v1.auth.verification.verify', now()->addHour(), ['id' => 1, 'hash' => 'x'], absolute: false);
    }
}
