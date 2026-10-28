<?php

namespace App\Actions\Registrations;

use App\Models\Event;
use App\Models\User;

/**
 * Cancels a user's registration. Idempotent: cancelling a registration that does
 * not exist is a no-op, so `DELETE` can safely be retried.
 */
class CancelRegistration
{
    public function handle(Event $event, User $user): bool
    {
        $cancelled = $event->registrations()->where('user_id', $user->id)->delete() > 0;

        if ($cancelled) {
            // The seat count changed, so the event belongs in the updated_since feed again.
            $event->touch();
        }

        return $cancelled;
    }
}
