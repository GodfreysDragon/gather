<?php

namespace Tests\Feature\Rest;

use App\Models\User;
use App\Notifications\AccountAlreadyExists;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * The API request the SPA makes for the link in a confirmation e-mail.
     */
    private function verificationRequest(User $user): string
    {
        $link = null;
        Notification::assertSentTo($user, VerifyEmailAddress::class, function (VerifyEmailAddress $notification) use ($user, &$link) {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertStringStartsWith(config('app.frontend_url').'/verify-email?', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return "/api/v1/auth/email/verify/{$query['id']}/{$query['hash']}?".http_build_query([
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }

    private function register(string $email = 'ada@example.com'): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => $email, 'password' => 'correct horse']);
    }

    public function test_registering_sends_a_confirmation_link_and_issues_no_token(): void
    {
        $this->register()
            ->assertAccepted()
            ->assertJsonMissingPath('token')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Check your inbox'));

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }

    public function test_registering_a_taken_address_answers_the_same_and_tells_only_its_owner(): void
    {
        $owner = User::factory()->create(['email' => 'taken@example.com']);

        $taken = $this->register('Taken@Example.com')->assertAccepted();
        $fresh = $this->register('new@example.com')->assertAccepted();

        $this->assertSame($fresh->json(), $taken->json());
        $this->assertSame(2, User::count());
        Notification::assertSentTo($owner, AccountAlreadyExists::class);
        Notification::assertNotSentTo($owner, VerifyEmailAddress::class);
    }

    public function test_registering_again_before_confirming_resends_the_link(): void
    {
        $this->register()->assertAccepted();
        $this->register()->assertAccepted();

        Notification::assertSentToTimes(User::where('email', 'ada@example.com')->firstOrFail(), VerifyEmailAddress::class, 2);
    }

    public function test_the_link_confirms_the_address_and_logs_the_user_in(): void
    {
        $this->register()->assertAccepted();
        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $tokens = $this->postJson($this->verificationRequest($user))
            ->assertOk()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->json();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$tokens['token']}"])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'correct horse'])->assertOk();
    }

    public function test_a_link_can_only_be_used_once(): void
    {
        $this->register()->assertAccepted();
        $request = $this->verificationRequest(User::where('email', 'ada@example.com')->firstOrFail());

        $this->postJson($request)->assertOk();
        $this->postJson($request)
            ->assertStatus(410)
            ->assertJsonPath('type', url('/problems/verification-link-invalid'));
    }

    public function test_tampered_and_expired_links_are_rejected(): void
    {
        $this->register()->assertAccepted();
        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $request = $this->verificationRequest($user);

        $this->postJson(str_replace("/verify/{$user->id}/", '/verify/999/', $request))->assertForbidden();

        $this->travel(VerifyEmailAddress::EXPIRES_IN_MINUTES + 1)->minutes();
        $this->postJson($request)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unconfirmed_account_cannot_log_in_but_only_its_owner_learns_why(): void
    {
        User::factory()->unverified()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('type', url('/problems/email-not-verified'));

        // Without the password it fails like any other wrong login.
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_resending_answers_the_same_whatever_the_address(): void
    {
        $unconfirmed = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $confirmed = User::factory()->create(['email' => 'done@example.com']);

        $responses = collect(['pending@example.com', 'done@example.com', 'nobody@example.com'])
            ->map(fn (string $email) => $this->postJson('/api/v1/auth/email/resend', ['email' => $email])->assertAccepted()->json());

        $this->assertCount(1, $responses->unique());
        Notification::assertSentTo($unconfirmed, VerifyEmailAddress::class);
        Notification::assertNothingSentTo($confirmed);
    }
}
