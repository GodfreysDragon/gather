<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Upcoming events the authenticated user is registered for, soonest first.
     */
    public function registrations(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $events = $user->registeredEvents()
            ->with(['venue', 'organizer'])
            ->withApiCounts($user)
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->get();

        return EventResource::collection($events);
    }
}
