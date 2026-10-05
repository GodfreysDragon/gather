<?php

namespace Tests\Feature\Rest;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(Venue $venue, array $overrides = []): array
    {
        return [
            'venue_id' => $venue->id,
            'title' => 'API design workshop',
            'description' => 'Hands-on session.',
            'starts_at' => now()->addWeek()->setTime(18, 0)->toIso8601String(),
            'ends_at' => now()->addWeek()->setTime(21, 0)->toIso8601String(),
            'capacity' => 40,
            ...$overrides,
        ];
    }

    public function test_an_authenticated_user_can_create_an_event_and_becomes_its_organizer(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());
        $venue = Venue::factory()->create(['capacity' => 100]);

        $response = $this->postJson('/api/v1/events', $this->payload($venue))
            ->assertCreated()
            ->assertJsonPath('data.title', 'API design workshop')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.organizer.id', $user->id)
            ->assertJsonPath('data.spots_left', 40);

        $response->assertHeader('Location', route('v1.events.show', $response->json('data.id')));
    }

    public function test_times_with_an_offset_are_stored_and_returned_in_utc(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $venue = Venue::factory()->create(['capacity' => 100]);
        $date = now()->addMonth()->format('Y-m-d');

        $this->postJson('/api/v1/events', $this->payload($venue, [
            'starts_at' => "{$date}T20:00:00+02:00",
            'ends_at' => "{$date}T22:00:00+02:00",
        ]))
            ->assertCreated()
            ->assertJsonPath('data.starts_at', "{$date}T18:00:00Z");
    }

    public function test_creating_an_event_requires_authentication(): void
    {
        $this->postJson('/api/v1/events', $this->payload(Venue::factory()->create()))->assertUnauthorized();
    }

    public function test_event_input_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $venue = Venue::factory()->create(['capacity' => 50]);

        $this->postJson('/api/v1/events', $this->payload($venue, [
            'title' => '',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->subDays(2)->toIso8601String(),
            'capacity' => 51,
            'status' => 'cancelled',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'starts_at', 'ends_at', 'capacity', 'status'])
            ->assertJsonPath('errors.capacity.0', "The capacity may not exceed the venue's capacity of 50.");
    }

    public function test_only_the_organizer_can_update_an_event(): void
    {
        $event = Event::factory()->create();

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/events/{$event->id}", ['title' => 'Hijacked'])
            ->assertForbidden()
            ->assertJsonPath('status', 403);

        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/v1/events/{$event->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_a_partial_update_validates_against_the_existing_values(): void
    {
        $event = Event::factory()->create();
        Sanctum::actingAs($event->organizer);

        $this->patchJson("/api/v1/events/{$event->id}", [
            'ends_at' => $event->starts_at->subHour()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $this->patchJson("/api/v1/events/{$event->id}", [
            'starts_at' => $event->ends_at->addHour()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_capacity_cannot_drop_below_the_number_of_registrations(): void
    {
        $event = Event::factory()->capacity(10)->create();
        $event->attendees()->attach(User::factory(3)->create());
        Sanctum::actingAs($event->organizer);

        $this->patchJson("/api/v1/events/{$event->id}", ['capacity' => 2])
            ->assertConflict()
            ->assertJsonPath('type', url('/problems/capacity-conflict'))
            ->assertJsonPath('detail', 'Capacity cannot be lowered to 2: 3 people are already registered.');

        $this->patchJson("/api/v1/events/{$event->id}", ['capacity' => 3])->assertOk();
    }

    public function test_an_event_without_registrations_can_be_deleted(): void
    {
        $event = Event::factory()->create();
        Sanctum::actingAs($event->organizer);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertNoContent();

        $this->assertModelMissing($event);
    }

    public function test_an_event_with_registrations_must_be_cancelled_instead_of_deleted(): void
    {
        $event = Event::factory()->create();
        $event->attendees()->attach(User::factory()->create());
        Sanctum::actingAs($event->organizer);

        $this->deleteJson("/api/v1/events/{$event->id}")
            ->assertConflict()
            ->assertJsonPath('type', url('/problems/event-has-registrations'));

        $this->patchJson("/api/v1/events/{$event->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_venues_can_be_listed_and_created(): void
    {
        Venue::factory()->create(['city' => 'Berlin']);
        Venue::factory()->create(['city' => 'Lisbon']);

        $this->getJson('/api/v1/venues?city=Berlin')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/venues', ['name' => 'Loft', 'address' => '1 Main St', 'city' => 'Austin', 'capacity' => 80])
            ->assertCreated()
            ->assertJsonPath('data.city', 'Austin');
    }
}
