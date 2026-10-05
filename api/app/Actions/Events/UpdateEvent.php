<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Exceptions\CapacityConflictException;
use App\Models\Event;
use App\Rules\FitsVenue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Partially updates an event. Fields that are omitted keep their current value,
 * and cross-field rules (end after start, capacity fits venue) are checked against
 * the merged result, not just the submitted fields.
 */
class UpdateEvent
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Event $event, array $input): Event
    {
        $data = Validator::make($input, $this->rules($event, $input))->validate();

        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = CarbonImmutable::parse($data[$field])->utc();
            }
        }

        return DB::transaction(function () use ($event, $data) {
            if (isset($data['capacity'])) {
                $registered = $event->registrations()->lockForUpdate()->count();

                if ($data['capacity'] < $registered) {
                    throw new CapacityConflictException($data['capacity'], $registered);
                }
            }

            $event->update($data);

            return $event->load('venue', 'organizer')->loadCount('registrations');
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function rules(Event $event, array $input): array
    {
        $startsAt = array_key_exists('starts_at', $input) ? 'starts_at' : $event->starts_at->toIso8601String();
        $endsAt = array_key_exists('ends_at', $input) ? 'ends_at' : $event->ends_at->toIso8601String();

        return [
            'venue_id' => ['sometimes', 'integer', 'exists:venues,id'],
            'title' => ['sometimes', 'string', 'min:3', 'max:120'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'starts_at' => ['sometimes', 'date', 'after:now', "before:{$endsAt}"],
            'ends_at' => ['sometimes', 'date', "after:{$startsAt}"],
            'capacity' => ['sometimes', 'integer', 'min:1', new FitsVenue($input['venue_id'] ?? $event->venue_id)],
            'status' => ['sometimes', Rule::enum(EventStatus::class)],
        ];
    }
}
