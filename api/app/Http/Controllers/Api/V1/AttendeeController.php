<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationResource;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendeeController extends Controller
{
    /**
     * Who has registered for an event. Organizer only.
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAttendees', $event);

        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $registrations = $event->registrations()
            ->with('user')
            ->oldest()
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return RegistrationResource::collection($registrations);
    }
}
