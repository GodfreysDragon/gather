<?php

namespace Tests\Feature\Rest;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_is_idempotent(): void
    {
        $event = Event::factory()->capacity(5)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/v1/events/{$event->id}/registration")
            ->assertCreated()
            ->assertJsonPath('data.event.viewer.is_registered', true)
            ->assertJsonPath('data.event.spots_left', 4);

        $this->putJson("/api/v1/events/{$event->id}/registration")
            ->assertOk()
            ->assertJsonPath('data.event.spots_left', 4);

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_a_full_event_rejects_new_registrations(): void
    {
        $event = Event::factory()->capacity(1)->create(['title' => 'Tiny meetup']);
        $event->attendees()->attach(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/v1/events/{$event->id}/registration")
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'type' => url('/problems/event-full'),
                'title' => 'Event is full',
                'status' => 409,
                'detail' => '"Tiny meetup" has no spots left.',
            ]);
    }

    public function test_an_existing_attendee_of_a_full_event_still_gets_their_registration_back(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->capacity(1)->create();
        $event->attendees()->attach($user);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/events/{$event->id}/registration")->assertOk();
    }

    public function test_cancelled_and_past_events_do_not_accept_registrations(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ([Event::factory()->cancelled()->create(), Event::factory()->past()->create()] as $event) {
            $this->putJson("/api/v1/events/{$event->id}/registration")
                ->assertConflict()
                ->assertJsonPath('type', url('/problems/registration-closed'));
        }
    }

    public function test_drafts_cannot_be_registered_for_by_others(): void
    {
        $event = Event::factory()->draft()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/v1/events/{$event->id}/registration")->assertNotFound();
    }

    public function test_cancelling_a_registration_is_idempotent(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $event->attendees()->attach($user);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/events/{$event->id}/registration")->assertNoContent();
        $this->deleteJson("/api/v1/events/{$event->id}/registration")->assertNoContent();

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_only_the_organizer_can_list_attendees(): void
    {
        $event = Event::factory()->create();
        $event->attendees()->attach(User::factory(3)->create());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/events/{$event->id}/attendees")->assertForbidden();

        Sanctum::actingAs($event->organizer);
        $this->getJson("/api/v1/events/{$event->id}/attendees")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'user' => ['id', 'name'], 'registered_at']]])
            ->assertJsonMissingPath('data.0.user.email');
    }

    public function test_me_registrations_lists_upcoming_events_the_user_registered_for(): void
    {
        $user = User::factory()->create();
        $later = Event::factory()->create(['starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour()]);
        $sooner = Event::factory()->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        $past = Event::factory()->past()->create();
        Event::factory()->create();

        foreach ([$later, $sooner, $past] as $event) {
            $event->attendees()->attach($user);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/registrations')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$sooner->id, $later->id])
            ->assertJsonPath('data.0.viewer.is_registered', true);
    }
}
