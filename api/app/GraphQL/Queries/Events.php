<?php

namespace App\GraphQL\Queries;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Query builder for the paginated `events` field. Maps the GraphQL arguments onto
 * the same Event::filter() scope that GET /api/v1/events uses.
 */
class Events
{
    /**
     * @param  array<string, mixed>  $args
     * @return Builder<Event>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Builder
    {
        $viewer = $context->user();

        return Event::query()
            ->withApiCounts($viewer)
            ->filter([
                'q' => $args['q'] ?? null,
                'city' => $args['city'] ?? null,
                'from' => isset($args['from']) ? $args['from']->toIso8601String() : null,
                'to' => isset($args['to']) ? $args['to']->toIso8601String() : null,
                'venue_id' => $args['venueId'] ?? null,
                'organizer_id' => $args['organizerId'] ?? null,
                'status' => $args['status'] ?? null,
                'has_spots' => $args['hasSpots'] ?? false,
                'sort' => $args['sort'] ?? null,
            ], $viewer);
    }
}
