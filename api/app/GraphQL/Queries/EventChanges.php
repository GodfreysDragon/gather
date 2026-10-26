<?php

namespace App\GraphQL\Queries;

use App\Models\Event;
use Carbon\CarbonInterface;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * The change feed, like GET /api/v1/events?updated_since=. It uses real (keyset)
 * cursors rather than Lighthouse's connection cursors, which encode offsets and
 * would skip events that move to the end of the feed when they change.
 */
class EventChanges
{
    /**
     * @param  array{updatedSince: CarbonInterface, first: int, after?: ?string}  $args
     * @return array{data: list<Event>, nextCursor: ?string, hasMore: bool}
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $viewer = $context->user();

        $page = Event::query()
            ->with(['venue', 'organizer'])
            ->withApiCounts($viewer)
            ->filter(['updated_since' => $args['updatedSince']->toIso8601String()], $viewer)
            ->cursorPaginate($args['first'], cursor: $args['after'] ?? null);

        return [
            'data' => $page->items(),
            'nextCursor' => $page->nextCursor()?->encode(),
            'hasMore' => $page->hasMorePages(),
        ];
    }
}
