<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at->utc()->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->utc()->toIso8601ZuluString(),
            'status' => $this->status->value,
            'capacity' => $this->capacity,
            'registration_count' => $this->registrationCount(),
            'spots_left' => $this->spotsLeft(),
            'viewer' => [
                'is_registered' => $this->isRegistered($viewer),
                'is_organizer' => $viewer?->id === $this->organizer_id,
            ],
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'organizer' => new UserResource($this->whenLoaded('organizer')),
            'created_at' => $this->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->utc()->toIso8601ZuluString(),
            'links' => [
                'self' => route('v1.events.show', $this->resource),
                'registration' => route('v1.events.registration.update', $this->resource),
            ],
        ];
    }
}
