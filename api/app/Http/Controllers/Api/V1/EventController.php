<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Events\CreateEvent;
use App\Actions\Events\DeleteEvent;
use App\Actions\Events\UpdateEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListEventsRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class EventController extends Controller
{
    public function index(ListEventsRequest $request): AnonymousResourceCollection
    {
        $viewer = $request->user('sanctum');

        $query = Event::query()
            ->with(['venue', 'organizer'])
            ->withApiCounts($viewer)
            ->filter($request->filters(), $viewer);

        $events = $request->usesCursor()
            ? $query->cursorPaginate($request->integer('per_page', $request->has('updated_since') ? 50 : 12))
            : $query->paginate($request->integer('per_page', 12));

        return EventResource::collection($events->withQueryString());
    }

    public function store(Request $request, CreateEvent $createEvent): JsonResponse
    {
        Gate::authorize('create', Event::class);

        $event = $createEvent->handle($request->user(), $request->all());

        return (new EventResource($event->loadApiCounts($request->user())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('v1.events.show', $event));
    }

    public function show(Request $request, Event $event): EventResource
    {
        Gate::forUser($request->user('sanctum'))->authorize('view', $event);

        return new EventResource($event->load(['venue', 'organizer'])->loadApiCounts($request->user('sanctum')));
    }

    public function update(Request $request, Event $event, UpdateEvent $updateEvent): EventResource
    {
        Gate::authorize('update', $event);

        $event = $updateEvent->handle($event, $request->all());

        return new EventResource($event->loadApiCounts($request->user()));
    }

    public function destroy(Event $event, DeleteEvent $deleteEvent): Response
    {
        Gate::authorize('delete', $event);

        $deleteEvent->handle($event);

        return response()->noContent();
    }
}
