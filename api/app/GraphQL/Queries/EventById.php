<?php

namespace App\GraphQL\Queries;

use App\Models\Event;
use Illuminate\Support\Facades\Gate;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class EventById
{
    /**
     * Returns null rather than an error for missing or hidden events, which is the
     * GraphQL convention for a nullable lookup field.
     *
     * @param  array{id: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?Event
    {
        $viewer = $context->user();
        $event = Event::query()->withApiCounts($viewer)->find($args['id']);

        if ($event === null || Gate::forUser($viewer)->denies('view', $event)) {
            return null;
        }

        return $event;
    }
}
