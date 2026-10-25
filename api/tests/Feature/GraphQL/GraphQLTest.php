<?php

namespace Tests\Feature\GraphQL;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class GraphQLTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    public function test_events_can_be_queried_with_nested_relations_and_pagination(): void
    {
        $venue = Venue::factory()->create(['city' => 'Lisbon']);
        Event::factory(3)->for($venue)->create();
        Event::factory(2)->create();

        $this->graphQL(/** @lang GraphQL */ '
            query ($city: String) {
                events(city: $city, first: 2) {
                    data { id title spotsLeft venue { city } organizer { name } }
                    paginatorInfo { total currentPage lastPage }
                }
            }
        ', ['city' => 'Lisbon'])
            ->assertGraphQLErrorFree()
            ->assertJsonCount(2, 'data.events.data')
            ->assertJsonPath('data.events.data.0.venue.city', 'Lisbon')
            ->assertJsonPath('data.events.paginatorInfo.total', 3)
            ->assertJsonPath('data.events.paginatorInfo.lastPage', 2);
    }

    public function test_a_repeated_query_is_served_from_the_query_cache(): void
    {
        // Regression: with a serializing cache store, the second run used to fail with
        // "__PHP_Incomplete_Class returned" because cached ASTs couldn't be unserialized.
        config(['cache.default' => 'database']);
        Event::factory()->create(['title' => 'Cached']);
        $query = /** @lang GraphQL */ '{ events(q: "Cached") { data { title } } }';

        foreach (range(1, 2) as $run) {
            $this->graphQL($query)->assertGraphQLErrorFree()->assertJsonPath('data.events.data.0.title', 'Cached');
        }
    }

    public function test_rest_and_graphql_return_the_same_events_for_the_same_filters(): void
    {
        $venue = Venue::factory()->create(['city' => 'Berlin', 'capacity' => 500]);
        Event::factory(4)->for($venue)->create();
        $full = Event::factory()->for($venue)->capacity(1)->create();
        $full->attendees()->attach(User::factory()->create());
        Event::factory(3)->for(Venue::factory()->create(['city' => 'Austin']))->create();

        $rest = $this->getJson('/api/v1/events?city=Berlin&has_spots=1&sort=-starts_at')->json('data.*.id');

        $graphql = $this->graphQL(/** @lang GraphQL */ '
            { events(city: "Berlin", hasSpots: true, sort: STARTS_AT_DESC) { data { id } } }
        ')->json('data.events.data.*.id');

        $this->assertCount(4, $rest);
        $this->assertSame($rest, array_map('intval', $graphql));
    }

    public function test_event_returns_null_for_drafts_the_viewer_does_not_organize(): void
    {
        $draft = Event::factory()->draft()->create();
        $query = /** @lang GraphQL */ 'query ($id: ID!) { event(id: $id) { id status viewerIsOrganizer } }';

        $this->graphQL($query, ['id' => $draft->id])->assertJsonPath('data.event', null);

        Sanctum::actingAs($draft->organizer);

        $this->graphQL($query, ['id' => $draft->id])
            ->assertJsonPath('data.event.status', 'DRAFT')
            ->assertJsonPath('data.event.viewerIsOrganizer', true);
    }

    public function test_me_is_null_when_anonymous_and_includes_private_fields_when_authenticated(): void
    {
        $query = /** @lang GraphQL */ '{ me { email registeredEvents { title } organizedEvents { title status } } }';

        $this->graphQL($query)->assertGraphQLErrorFree()->assertJsonPath('data.me', null);

        $user = User::factory()->create(['email' => 'ada@example.com']);
        Event::factory()->create(['title' => 'Attending'])->attendees()->attach($user);
        Event::factory()->for($user, 'organizer')->draft()->create(['title' => 'Organizing']);
        Sanctum::actingAs($user);

        $this->graphQL($query)
            ->assertJsonPath('data.me.email', 'ada@example.com')
            ->assertJsonPath('data.me.registeredEvents.0.title', 'Attending')
            ->assertJsonPath('data.me.organizedEvents.0.status', 'DRAFT');
    }

    public function test_mutations_require_authentication(): void
    {
        $event = Event::factory()->create();

        $this->graphQL(/** @lang GraphQL */ 'mutation ($id: ID!) { registerForEvent(eventId: $id) { created } }', ['id' => $event->id])
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_create_event_uses_the_shared_validation_with_graphql_field_names(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $venue = Venue::factory()->create(['capacity' => 50]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($input: CreateEventInput!) { createEvent(input: $input) { id } }
        ', ['input' => [
            'venueId' => $venue->id,
            'title' => 'GraphQL night',
            'description' => 'Schemas and resolvers.',
            'startsAt' => now()->addWeek()->toIso8601String(),
            'endsAt' => now()->addWeek()->subHour()->toIso8601String(),
            'capacity' => 60,
        ]])->assertGraphQLValidationKeys(['input.endsAt', 'input.capacity']);
    }

    public function test_create_event_returns_the_new_event(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());
        $venue = Venue::factory()->create(['capacity' => 50]);
        $date = now()->addMonth()->format('Y-m-d');

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($input: CreateEventInput!) {
                createEvent(input: $input) { title status startsAt spotsLeft viewerIsOrganizer organizer { id } }
            }
        ', ['input' => [
            'venueId' => $venue->id,
            'title' => 'GraphQL night',
            'description' => 'Schemas and resolvers.',
            'startsAt' => "{$date}T20:00:00+02:00",
            'endsAt' => "{$date}T22:00:00+02:00",
            'capacity' => 30,
            'status' => 'DRAFT',
        ]])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.createEvent.status', 'DRAFT')
            ->assertJsonPath('data.createEvent.startsAt', "{$date}T18:00:00Z")
            ->assertJsonPath('data.createEvent.spotsLeft', 30)
            ->assertJsonPath('data.createEvent.organizer.id', (string) $user->id);
    }

    public function test_update_event_is_authorized_by_the_shared_policy(): void
    {
        $event = Event::factory()->create();
        $mutation = /** @lang GraphQL */ 'mutation ($id: ID!) { updateEvent(id: $id, input: { title: "Renamed" }) { title } }';

        Sanctum::actingAs(User::factory()->create());
        $this->graphQL($mutation, ['id' => $event->id])
            ->assertGraphQLErrorMessage('This action is unauthorized.')
            ->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');

        Sanctum::actingAs($event->organizer);
        $this->graphQL($mutation, ['id' => $event->id])->assertJsonPath('data.updateEvent.title', 'Renamed');
    }

    public function test_registration_is_idempotent_and_business_errors_carry_a_code(): void
    {
        $event = Event::factory()->capacity(1)->create();
        Sanctum::actingAs(User::factory()->create());
        $mutation = /** @lang GraphQL */ 'mutation ($id: ID!) { registerForEvent(eventId: $id) { created event { spotsLeft viewerIsRegistered } } }';

        $this->graphQL($mutation, ['id' => $event->id])
            ->assertJsonPath('data.registerForEvent.created', true)
            ->assertJsonPath('data.registerForEvent.event.spotsLeft', 0)
            ->assertJsonPath('data.registerForEvent.event.viewerIsRegistered', true);

        $this->graphQL($mutation, ['id' => $event->id])->assertJsonPath('data.registerForEvent.created', false);

        Sanctum::actingAs(User::factory()->create());

        $this->graphQL($mutation, ['id' => $event->id])
            ->assertJsonPath('errors.0.extensions.code', 'EVENT_FULL')
            ->assertJsonPath('errors.0.message', "\"{$event->title}\" has no spots left.");
    }

    public function test_unknown_events_produce_a_not_found_error(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->graphQL(/** @lang GraphQL */ 'mutation { cancelRegistration(eventId: 999) { id } }')
            ->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');
    }

    public function test_attendees_are_only_visible_to_the_organizer(): void
    {
        $event = Event::factory()->create();
        $event->attendees()->attach(User::factory(2)->create());
        $query = /** @lang GraphQL */ 'query ($id: ID!) { event(id: $id) { attendees { data { user { name } } } } }';

        Sanctum::actingAs(User::factory()->create());
        $this->graphQL($query, ['id' => $event->id])
            ->assertGraphQLErrorMessage('This action is unauthorized.')
            ->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');

        Sanctum::actingAs($event->organizer);
        $this->graphQL($query, ['id' => $event->id])->assertJsonCount(2, 'data.event.attendees.data');
    }

    public function test_overly_expensive_queries_are_rejected_before_execution(): void
    {
        $this->graphQL(/** @lang GraphQL */ '{ events(first: 12) { data { venue { id } organizer { id } } } }')
            ->assertGraphQLErrorFree();

        // Complexity scales with the requested page size, so aliasing several
        // maximum-size pages of the same list is caught before any query runs.
        $page = 'events(first: 50) { data { id title description startsAt endsAt venue { name city } organizer { name } } }';
        $message = $this->graphQL("{ a: {$page} b: {$page} c: {$page} }")->json('errors.0.message');

        $this->assertStringStartsWith('Max query complexity should be 1000', $message);
    }

    public function test_mutations_on_someone_elses_draft_report_not_found(): void
    {
        $draft = Event::factory()->draft()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->graphQL(/** @lang GraphQL */ 'mutation ($id: ID!) { updateEvent(id: $id, input: { title: "Hijacked" }) { id } }', ['id' => $draft->id])
            ->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');

        $this->graphQL(/** @lang GraphQL */ 'mutation ($id: ID!) { deleteEvent(id: $id) }', ['id' => $draft->id])
            ->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');
    }

    public function test_an_event_with_registrations_cannot_go_back_to_draft(): void
    {
        $event = Event::factory()->create();
        $event->attendees()->attach(User::factory()->create());
        Sanctum::actingAs($event->organizer);

        $this->graphQL(/** @lang GraphQL */ 'mutation ($id: ID!) { updateEvent(id: $id, input: { status: DRAFT }) { status } }', ['id' => $event->id])
            ->assertJsonPath('errors.0.extensions.code', 'EVENT_HAS_REGISTRATIONS');
    }
}
