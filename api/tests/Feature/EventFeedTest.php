<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class EventFeedTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    public function test_cursor_pagination_visits_every_event_once_even_with_tied_sort_keys(): void
    {
        // Five events share a start time, so only the id tiebreaker keeps the cursor stable.
        $startsAt = now()->addWeek()->setTime(18, 0);
        Event::factory(5)->create(['starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHours(2)]);
        Event::factory(4)->create();

        $seen = [];
        $url = '/api/v1/events?pagination=cursor&per_page=2';

        while ($url !== null) {
            $response = $this->getJson($url)->assertOk()->assertJsonStructure(['meta' => ['next_cursor', 'prev_cursor', 'per_page']]);
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $next = $response->json('meta.next_cursor');
            $url = $next === null ? null : '/api/v1/events?per_page=2&cursor='.urlencode($next);
        }

        $this->assertCount(9, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)));
        $this->assertEqualsCanonicalizing(Event::pluck('id')->all(), $seen);
    }

    public function test_the_change_feed_lists_changes_oldest_first_and_resumes_from_a_cursor(): void
    {
        $this->travelTo(now()->subDays(3));
        $old = Event::factory()->create(['title' => 'Untouched']);
        $this->travelBack();

        $since = now()->subDay()->toIso8601String();

        $this->travelTo(now()->subHours(3));
        $first = Event::factory()->create(['title' => 'Created yesterday']);
        $this->travelTo(now()->addHour());
        $second = Event::factory()->create(['title' => 'Created later']);
        $this->travelTo(now()->addHour());
        $old->update(['title' => 'Edited last']);
        $this->travelBack();

        $page = $this->getJson('/api/v1/events?'.http_build_query(['updated_since' => $since, 'per_page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);

        $this->getJson('/api/v1/events?'.http_build_query(['updated_since' => $since, 'per_page' => 2, 'cursor' => $page->json('meta.next_cursor')]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('data.0.title', 'Edited last')
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_deleted_events_appear_in_the_feed_as_tombstones_but_nowhere_else(): void
    {
        $since = now()->subMinute()->toIso8601String();
        $event = Event::factory()->create();
        Sanctum::actingAs($event->organizer);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertNoContent();

        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/events?'.http_build_query(['updated_since' => $since]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.deleted_at', fn (?string $deletedAt) => $deletedAt !== null);
    }

    public function test_the_feed_never_shows_other_peoples_drafts(): void
    {
        $since = now()->subMinute()->toIso8601String();
        $draft = Event::factory()->draft()->create();
        $public = Event::factory()->create();
        $query = '/api/v1/events?'.http_build_query(['updated_since' => $since]);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson($query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $public->id);

        Sanctum::actingAs($draft->organizer);
        $this->getJson($query)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_feed_cannot_be_sorted(): void
    {
        $this->getJson('/api/v1/events?'.http_build_query(['updated_since' => now()->toIso8601String(), 'sort' => 'title']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('updated_since');
    }

    public function test_rest_and_graphql_feeds_agree(): void
    {
        $since = now()->subMinute()->toIso8601String();
        Event::factory(3)->create();
        $deleted = Event::factory()->create();
        $deleted->delete();

        $rest = $this->getJson('/api/v1/events?'.http_build_query(['updated_since' => $since]))->json('data');

        $graphql = $this->graphQL(/** @lang GraphQL */ '
            query ($since: DateTime!) {
                eventChanges(updatedSince: $since, first: 2) { data { id deletedAt } nextCursor hasMore }
            }', ['since' => $since]);

        $graphql->assertJsonPath('data.eventChanges.hasMore', true);
        $next = $this->graphQL(/** @lang GraphQL */ '
            query ($since: DateTime!, $after: String) {
                eventChanges(updatedSince: $since, first: 2, after: $after) { data { id deletedAt } hasMore }
            }', ['since' => $since, 'after' => $graphql->json('data.eventChanges.nextCursor')])
            ->assertJsonPath('data.eventChanges.hasMore', false);

        $graphqlIds = array_map('intval', array_column([
            ...$graphql->json('data.eventChanges.data'),
            ...$next->json('data.eventChanges.data'),
        ], 'id'));

        $this->assertSame(array_column($rest, 'id'), $graphqlIds);
        $this->assertNotNull($next->json('data.eventChanges.data.1.deletedAt'));
    }
}
