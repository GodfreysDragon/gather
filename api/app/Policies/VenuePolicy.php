<?php

namespace App\Policies;

use App\Models\User;

/**
 * Venues are shared by every organizer, so only admins may add them.
 */
class VenuePolicy
{
    public function create(User $user): bool
    {
        return $user->is_admin;
    }
}
