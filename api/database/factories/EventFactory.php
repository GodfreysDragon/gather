<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+3 months');

        return [
            'organizer_id' => User::factory(),
            'venue_id' => Venue::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'description' => fake()->paragraph(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+3 hours'),
            // Never more than the venue holds, like the API's own FitsVenue rule.
            'capacity' => fn (array $attributes) => min(fake()->numberBetween(10, 100), Venue::findOrFail($attributes['venue_id'])->capacity),
            'status' => EventStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => EventStatus::Draft]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => EventStatus::Cancelled]);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHours(3),
        ]);
    }

    public function capacity(int $capacity): static
    {
        return $this->state(['capacity' => $capacity]);
    }
}
