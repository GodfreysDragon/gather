<?php

namespace Tests\Feature\Rest;

use App\Http\Middleware\Idempotent;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'venue_id' => Venue::factory()->create(['capacity' => 100])->id,
            'title' => 'Retry-safe workshop',
            'description' => 'Created exactly once.',
            'starts_at' => now()->addWeek()->setTime(18, 0)->toIso8601String(),
            'ends_at' => now()->addWeek()->setTime(20, 0)->toIso8601String(),
            'capacity' => 30,
            ...$overrides,
        ];
    }

    public function test_repeating_a_create_with_the_same_key_replays_the_first_response(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = $this->payload();
        $headers = ['Idempotency-Key' => 'create-1'];

        $first = $this->postJson('/api/v1/events', $payload, $headers)
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->postJson('/api/v1/events', $payload, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('Location', $first->headers->get('Location'))
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Event::count());
    }

    public function test_reusing_a_key_with_a_different_body_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = $this->payload();
        $this->postJson('/api/v1/events', $payload, ['Idempotency-Key' => 'create-1'])->assertCreated();

        $this->postJson('/api/v1/events', [...$payload, 'title' => 'Something else'], ['Idempotency-Key' => 'create-1'])
            ->assertUnprocessable()
            ->assertJsonPath('type', url('/problems/idempotency-key-reused'));

        $this->assertSame(1, Event::count());
    }

    public function test_keys_are_scoped_to_the_caller(): void
    {
        $payload = $this->payload();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/events', $payload, ['Idempotency-Key' => 'shared'])->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/events', $payload, ['Idempotency-Key' => 'shared'])
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, Event::count());
    }

    public function test_a_duplicate_of_a_request_still_running_is_a_conflict(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());
        $lock = Cache::lock(Idempotent::cacheKey($user->id, 'POST', 'api/v1/events', 'busy').':lock', 30);
        $lock->get();

        $this->postJson('/api/v1/events', $this->payload(), ['Idempotency-Key' => 'busy'])
            ->assertConflict()
            ->assertJsonPath('type', url('/problems/idempotency-in-progress'));

        $lock->release();
        $this->assertSame(0, Event::count());
    }

    public function test_validation_errors_are_replayed_too_and_requests_without_a_key_are_unaffected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $invalid = $this->payload(['title' => '']);

        $this->postJson('/api/v1/events', $invalid, ['Idempotency-Key' => 'bad'])->assertUnprocessable();
        $this->postJson('/api/v1/events', $invalid, ['Idempotency-Key' => 'bad'])
            ->assertUnprocessable()
            ->assertHeader('Idempotent-Replayed', 'true');

        $payload = $this->payload();
        $this->postJson('/api/v1/events', $payload)->assertCreated();
        $this->postJson('/api/v1/events', $payload)->assertCreated();
        $this->assertSame(2, Event::count());
    }
}
