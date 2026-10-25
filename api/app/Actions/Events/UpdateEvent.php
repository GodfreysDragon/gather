<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Exceptions\CapacityConflictException;
use App\Exceptions\EventHasRegistrationsException;
use App\Models\Event;
use App\Models\Venue;
use App\Rules\FitsVenue;
use App\Rules\Iso8601DateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

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
        $validator = Validator::make($input, $this->rules($event, $input));
        $validator->after(fn (ValidationValidator $v) => $this->checkWholeEvent($v, $event, $input));
        $data = $validator->validate();

        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = CarbonImmutable::parse($data[$field])->utc();
            }
        }

        return DB::transaction(function () use ($event, $data) {
            if (isset($data['capacity']) || ($data['status'] ?? null) === EventStatus::Draft->value) {
                // The same row lock RegisterForEvent takes, so a registration can't slip in
                // between counting and saving.
                $registered = Event::query()->lockForUpdate()->findOrFail($event->id)->registrations()->count();

                if (isset($data['capacity']) && $data['capacity'] < $registered) {
                    throw new CapacityConflictException($data['capacity'], $registered);
                }

                // Attendees can't see, or cancel, a registration for a draft.
                if (($data['status'] ?? null) === EventStatus::Draft->value && $registered > 0) {
                    throw new EventHasRegistrationsException($registered, 'moving it back to draft');
                }
            }

            $event->update($data);

            return $event->load('venue', 'organizer')->loadCount('registrations');
        });
    }

    /**
     * Rules that need the merged event rather than a single submitted field.
     *
     * @param  array<string, mixed>  $input
     */
    private function checkWholeEvent(ValidationValidator $validator, Event $event, array $input): void
    {
        if (array_intersect_key($input, $this->rules($event, $input)) === []) {
            $validator->errors()->add('body', 'Provide at least one field to update.');

            return;
        }

        // Moving to a smaller venue must still fit the current capacity.
        if (isset($input['venue_id']) && ! array_key_exists('capacity', $input) && ! $validator->errors()->has('venue_id')) {
            $venue = Venue::find($input['venue_id']);

            if ($venue !== null && $event->capacity > $venue->capacity) {
                $validator->errors()->add('venue_id', "The venue's capacity of {$venue->capacity} is below the event's capacity of {$event->capacity}.");
            }
        }
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
            'starts_at' => ['sometimes', new Iso8601DateTime, 'date', 'after:now', "before:{$endsAt}"],
            'ends_at' => ['sometimes', new Iso8601DateTime, 'date', "after:{$startsAt}"],
            'capacity' => ['sometimes', 'integer', 'min:1', new FitsVenue($input['venue_id'] ?? $event->venue_id)],
            'status' => ['sometimes', Rule::enum(EventStatus::class)],
        ];
    }
}
