<?php

namespace App\Actions\Registrations;

use App\Exceptions\EventFullException;
use App\Exceptions\RegistrationClosedException;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a user for an event. Idempotent: registering twice returns the
 * existing registration instead of failing, which is what lets the REST API expose
 * this as `PUT /events/{id}/registration`.
 */
class RegisterForEvent
{
    /**
     * @return array{registration: Registration, created: bool}
     */
    public function handle(Event $event, User $user): array
    {
        try {
            return $this->register($event, $user);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request from the same user won the race. SQLite doesn't support
            // row locks, so this is the last line of defence there; either way the result is
            // the registration that now exists.
            return ['registration' => $event->registrations()->where('user_id', $user->id)->firstOrFail(), 'created' => false];
        }
    }

    /**
     * @return array{registration: Registration, created: bool}
     */
    private function register(Event $event, User $user): array
    {
        return DB::transaction(function () use ($event, $user) {
            // Lock the event row so two concurrent requests can't both take the last spot
            // (MySQL and PostgreSQL; SQLite serialises writers instead).
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            $existing = $event->registrations()->where('user_id', $user->id)->first();

            if ($existing !== null) {
                return ['registration' => $existing, 'created' => false];
            }

            if (! $event->status->acceptsRegistrations() || $event->starts_at->isPast()) {
                throw new RegistrationClosedException($event);
            }

            if ($event->registrations()->count() >= $event->capacity) {
                throw new EventFullException($event);
            }

            return [
                'registration' => $event->registrations()->create(['user_id' => $user->id]),
                'created' => true,
            ];
        });
    }
}
