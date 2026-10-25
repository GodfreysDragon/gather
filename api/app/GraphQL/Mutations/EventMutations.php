<?php

namespace App\GraphQL\Mutations;

use App\Actions\Events\CreateEvent;
use App\Actions\Events\DeleteEvent;
use App\Actions\Events\UpdateEvent;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Thin GraphQL adapters over the same actions the REST controllers use. The only
 * work done here is translating between GraphQL's camelCase input and the domain's
 * snake_case attributes, in both directions (validation error keys included).
 */
class EventMutations
{
    public function __construct(
        private CreateEvent $createEvent,
        private UpdateEvent $updateEvent,
        private DeleteEvent $deleteEvent,
    ) {}

    /**
     * @param  array{input: array<string, mixed>}  $args
     */
    public function create(mixed $root, array $args, GraphQLContext $context): Event
    {
        Gate::forUser($context->user())->authorize('create', Event::class);

        return $this->withGraphQLErrorKeys(fn () => $this->createEvent
            ->handle($context->user(), $this->toAttributes($args['input']))
            ->loadApiCounts($context->user()));
    }

    /**
     * @param  array{id: string, input: array<string, mixed>}  $args
     */
    public function update(mixed $root, array $args, GraphQLContext $context): Event
    {
        $event = $this->findVisibleEvent($args['id'], $context);
        Gate::forUser($context->user())->authorize('update', $event);

        return $this->withGraphQLErrorKeys(fn () => $this->updateEvent
            ->handle($event, $this->toAttributes($args['input']))
            ->loadApiCounts($context->user()));
    }

    /**
     * @param  array{id: string}  $args
     */
    public function delete(mixed $root, array $args, GraphQLContext $context): string
    {
        $event = $this->findVisibleEvent($args['id'], $context);
        Gate::forUser($context->user())->authorize('delete', $event);

        $this->deleteEvent->handle($event);

        return (string) $event->id;
    }

    /**
     * Someone else's draft is "not found", exactly as in the `event` query and the REST API.
     */
    private function findVisibleEvent(string $id, GraphQLContext $context): Event
    {
        $event = Event::find($id);

        if ($event === null || Gate::forUser($context->user())->denies('view', $event)) {
            throw new ResourceNotFoundException('Event');
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function toAttributes(array $input): array
    {
        return collect($input)
            ->mapWithKeys(fn (mixed $value, string $key) => [
                Str::snake($key) => $value instanceof CarbonInterface ? $value->toIso8601String() : $value,
            ])
            ->all();
    }

    /**
     * Re-key validation errors from `starts_at` to `input.startsAt`, Lighthouse's convention.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withGraphQLErrorKeys(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn (array $messages, string $key) => ['input.'.Str::camel($key) => $messages])
                ->all());
        }
    }
}
