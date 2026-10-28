<?php

namespace App\Actions\Events;

use App\Exceptions\EventHasRegistrationsException;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an event that nobody has registered for yet. Once people have signed up,
 * the organizer has to cancel it instead (status "cancelled"), so attendees can
 * still see what happened to it.
 */
class DeleteEvent
{
    public function handle(Event $event): void
    {
        DB::transaction(function () use ($event) {
            // The same row lock RegisterForEvent takes, so nobody can register in between
            // the count and the delete.
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $registered = $event->registrations()->count();

            if ($registered > 0) {
                throw new EventHasRegistrationsException($registered);
            }

            $event->delete();
        });
    }
}
