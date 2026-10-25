<?php

namespace Tests\Feature\Rest;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_are_listed_publicly_with_pagination_metadata(): void
    {
        Event::factory(15)->create();

        $this->getJson('/api/v1/events?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.viewer.is_registered', false)
            ->assertJsonStructure(['data' => [['id', 'title', 'venue' => ['city'], 'organizer' => ['name'], 'links' => ['self']]]]);
    }

    public function test_events_can_be_searched_and_filtered(): void
    {
        $berlin = Venue::factory()->create(['city' => 'Berlin', 'capacity' => 500]);
        $lisbon = Venue::factory()->create(['city' => 'Lisbon', 'capacity' => 500]);

        $match = Event::factory()->for($berlin)->create(['title' => 'Vue meetup', 'starts_at' => now()->addDays(10)]);
        Event::factory()->for($lisbon)->create(['title' => 'Vue meetup', 'starts_at' => now()->addDays(10)]);
        Event::factory()->for($berlin)->create(['title' => 'Laravel meetup', 'starts_at' => now()->addDays(10)]);
        Event::factory()->for($berlin)->create(['title' => 'Vue conference', 'starts_at' => now()->addDays(40)]);

        $query = http_build_query([
            'q' => 'vue',
            'city' => 'Berlin',
            'from' => now()->addDays(5)->toIso8601String(),
            'to' => now()->addDays(20)->toIso8601String(),
        ]);

        $this->getJson("/api/v1/events?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_has_spots_excludes_full_events(): void
    {
        $full = Event::factory()->capacity(1)->create();
        $full->attendees()->attach(User::factory()->create());
        $open = Event::factory()->capacity(5)->create();

        $this->getJson('/api/v1/events?has_spots=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.spots_left', 5);
    }

    public function test_has_spots_only_includes_published_events(): void
    {
        $organizer = User::factory()->create();
        Event::factory()->cancelled()->capacity(50)->create();
        Event::factory()->draft()->for($organizer, 'organizer')->capacity(50)->create();
        $open = Event::factory()->capacity(5)->create();

        Sanctum::actingAs($organizer);

        $this->getJson('/api/v1/events?has_spots=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);
    }

    public function test_events_can_be_sorted(): void
    {
        Event::factory()->create(['title' => 'Bravo']);
        Event::factory()->create(['title' => 'Alpha']);
        Event::factory()->create(['title' => 'Charlie']);

        $this->getJson('/api/v1/events?sort=-title')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Charlie', 'Bravo', 'Alpha']);
    }

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $this->getJson('/api/v1/events?sort=password&per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_drafts_are_only_visible_to_their_organizer(): void
    {
        $organizer = User::factory()->create();
        $draft = Event::factory()->for($organizer, 'organizer')->draft()->create();
        Event::factory()->create();

        $this->getJson('/api/v1/events')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/events/{$draft->id}")
            ->assertNotFound()
            ->assertJsonPath('detail', 'Event not found.');

        Sanctum::actingAs($organizer);

        $this->getJson('/api/v1/events')->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/events/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.viewer.is_organizer', true);
    }

    public function test_the_viewer_block_reflects_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $event->attendees()->attach($user);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer.is_registered', true)
            ->assertJsonPath('data.registration_count', 1);
    }

    public function test_listing_uses_a_constant_number_of_queries(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Event::factory(2)->create();
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/events')->assertOk());

        Event::factory(10)->create();
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/events')->assertOk());

        $this->assertSame($few, $many, 'Listing events has an N+1 query.');
    }

    public function test_unknown_events_return_a_404_problem(): void
    {
        $this->getJson('/api/v1/events/999')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Event not found.');
    }

    public function test_get_responses_support_conditional_requests(): void
    {
        $event = Event::factory()->create();

        $etag = $this->getJson("/api/v1/events/{$event->id}")->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->getJson("/api/v1/events/{$event->id}", ['If-None-Match' => $etag])->assertStatus(304);

        $event->update(['title' => 'Renamed']);

        $this->getJson("/api/v1/events/{$event->id}", ['If-None-Match' => $etag])->assertOk();
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    public function test_search_treats_wildcard_characters_literally(): void
    {
        $match = Event::factory()->create(['title' => 'Save 50% on tickets']);
        Event::factory()->create(['title' => 'Save 500 euros']);

        $this->getJson('/api/v1/events?'.http_build_query(['q' => '50%']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }
}
