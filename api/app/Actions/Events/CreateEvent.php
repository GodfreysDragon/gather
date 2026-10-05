<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Rules\FitsVenue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates an event. Called by both the REST controller and the GraphQL mutation,
 * so validation lives here rather than in a transport-specific FormRequest.
 */
class CreateEvent
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $organizer, array $input): Event
    {
        $data = Validator::make($input, $this->rules($input))->validate();

        // Store instants in UTC; Eloquent would otherwise drop a client's offset.
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc();
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at'])->utc();

        return $organizer->organizedEvents()->create($data)->load('venue', 'organizer');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function rules(array $input): array
    {
        return [
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', new FitsVenue($input['venue_id'] ?? null)],
            'status' => ['sometimes', Rule::enum(EventStatus::class)->only([EventStatus::Draft, EventStatus::Published])],
        ];
    }
}
