<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\RegisterForEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The authenticated user's registration for an event, modelled as a singleton
 * sub-resource: PUT creates it (idempotently), DELETE removes it.
 */
class RegistrationController extends Controller
{
    public function update(Request $request, Event $event, RegisterForEvent $registerForEvent): JsonResponse
    {
        Gate::authorize('view', $event);

        ['registration' => $registration, 'created' => $created] = $registerForEvent->handle($event, $request->user());

        $registration->load('event.venue', 'event.organizer');
        $registration->event->loadApiCounts($request->user());

        return (new RegistrationResource($registration))
            ->response()
            ->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(Request $request, Event $event, CancelRegistration $cancelRegistration): Response
    {
        Gate::authorize('view', $event);

        $cancelRegistration->handle($event, $request->user());

        return response()->noContent();
    }
}
