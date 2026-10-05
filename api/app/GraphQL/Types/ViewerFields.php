<?php

namespace App\GraphQL\Types;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * There is only ever one Viewer per request, so these lists are loaded directly
 * (with their counts preloaded) instead of through a batched relation directive.
 */
class ViewerFields
{
    /**
     * @return Collection<int, Event>
     */
    public function registeredEvents(User $viewer): Collection
    {
        return $viewer->registeredEvents()
            ->withApiCounts($viewer)
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, Event>
     */
    public function organizedEvents(User $viewer): Collection
    {
        return $viewer->organizedEvents()
            ->withApiCounts($viewer)
            ->orderBy('starts_at')
            ->get();
    }
}
