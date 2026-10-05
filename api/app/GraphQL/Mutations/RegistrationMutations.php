<?php

namespace App\GraphQL\Mutations;

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\RegisterForEvent;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Event;
use Illuminate\Support\Facades\Gate;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class RegistrationMutations
{
    public function __construct(
        private RegisterForEvent $registerForEvent,
        private CancelRegistration $cancelRegistration,
    ) {}

    /**
     * @param  array{eventId: string}  $args
     * @return array{created: bool, event: Event}
     */
    public function register(mixed $root, array $args, GraphQLContext $context): array
    {
        $event = $this->findVisibleEvent($args['eventId'], $context);

        ['created' => $created] = $this->registerForEvent->handle($event, $context->user());

        return ['created' => $created, 'event' => $event->loadApiCounts($context->user())];
    }

    /**
     * @param  array{eventId: string}  $args
     */
    public function cancel(mixed $root, array $args, GraphQLContext $context): Event
    {
        $event = $this->findVisibleEvent($args['eventId'], $context);

        $this->cancelRegistration->handle($event, $context->user());

        return $event->loadApiCounts($context->user());
    }

    private function findVisibleEvent(string $id, GraphQLContext $context): Event
    {
        $event = Event::find($id);

        if ($event === null || Gate::forUser($context->user())->denies('view', $event)) {
            throw new ResourceNotFoundException('Event');
        }

        return $event;
    }
}
