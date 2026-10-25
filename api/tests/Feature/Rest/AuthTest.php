<?php

namespace Tests\Feature\Rest;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_an_account_and_returns_a_bearer_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse',
        ]);

        $response->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'ada@example.com');

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_register_rejects_a_duplicate_email_with_a_validation_problem(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'correct horse',
        ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 422)
            ->assertJsonValidationErrors('email');
    }

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
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada', 'email' => 'Ada@Example.com', 'password' => 'correct-horse',
        ])->assertCreated()->assertJsonPath('user.email', 'ada@example.com');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada again', 'email' => 'ada@example.com', 'password' => 'correct-horse',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', ['email' => 'ADA@example.com', 'password' => 'correct-horse'])->assertOk();
    }
}
