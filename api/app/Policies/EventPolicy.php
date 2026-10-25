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

    public function update(User $user, Event $event): Response
    {
        return $this->organizerOnly($user, $event);
    }

    public function delete(User $user, Event $event): Response
    {
        return $this->organizerOnly($user, $event);
    }

    public function viewAttendees(User $user, Event $event): Response
    {
        return $this->organizerOnly($user, $event);
    }

    /**
     * A 403 for events the user can see, but a 404 for someone else's draft, so writes
     * don't reveal a draft's existence any more than reads do.
     */
    private function organizerOnly(User $user, Event $event): Response
    {
        if ($this->isOrganizer($user, $event)) {
            return Response::allow();
        }

        return $event->status === EventStatus::Draft
            ? Response::denyAsNotFound('Event not found.')
            : Response::deny();
    }

    private function isOrganizer(?User $user, Event $event): bool
    {
        return $user !== null && $user->id === $event->organizer_id;
    }
}
