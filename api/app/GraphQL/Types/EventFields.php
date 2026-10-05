<?php

namespace App\GraphQL\Types;

use App\Models\Event;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Field resolvers for the `Event` type that need the viewer or a value conversion.
 */
class EventFields
{
    public function status(Event $event): string
    {
        return $event->status->value;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function viewerIsRegistered(Event $event, array $args, GraphQLContext $context): bool
    {
        return $event->isRegistered($context->user());
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function viewerIsOrganizer(Event $event, array $args, GraphQLContext $context): bool
    {
        return $context->user()?->id === $event->organizer_id;
    }
}
