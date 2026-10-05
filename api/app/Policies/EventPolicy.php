<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Shared by both transports: REST controllers call Gate::authorize(), the GraphQL
 * schema uses @canRoot and the mutation resolvers call the same abilities.
 */
class EventPolicy
{
    /**
     * Published and cancelled events are public; drafts are only visible to their organizer.
     * Others get a 404 rather than a 403, so the API doesn't reveal that a draft exists.
     */
    public function view(?User $user, Event $event): Response
    {
        return $event->status !== EventStatus::Draft || $this->isOrganizer($user, $event)
            ? Response::allow()
            : Response::denyAsNotFound('Event not found.');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Event $event): bool
    {
        return $this->isOrganizer($user, $event);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->isOrganizer($user, $event);
    }

    public function viewAttendees(User $user, Event $event): bool
    {
        return $this->isOrganizer($user, $event);
    }

    private function isOrganizer(?User $user, Event $event): bool
    {
        return $user !== null && $user->id === $event->organizer_id;
    }
}
