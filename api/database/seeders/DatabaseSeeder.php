<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a demo dataset. Sign in as demo@gather.test / password.
     *
     * The data is the same on every run (fixed Faker seed, Faker-picked attendees), so
     * the demo, the screenshots and the end-to-end test all see the same events.
     */
    public function run(): void
    {
        fake()->seed(2026);

        $demo = User::factory()->create(['name' => 'Demo User', 'email' => 'demo@gather.test']);
        $organizers = User::factory(4)->create();
        $attendees = User::factory(40)->create();

        $venues = collect([
            ['name' => 'Factory Hall', 'address' => 'Lohmühlenstraße 65', 'city' => 'Berlin', 'capacity' => 300],
            ['name' => 'Kreuzberg Loft', 'address' => 'Skalitzer Str. 85', 'city' => 'Berlin', 'capacity' => 60],
            ['name' => 'LX Factory Stage', 'address' => 'R. Rodrigues de Faria 103', 'city' => 'Lisbon', 'capacity' => 200],
            ['name' => 'Distillery Commons', 'address' => '55 Mill St', 'city' => 'Toronto', 'capacity' => 150],
            ['name' => 'Eastside Warehouse', 'address' => '2400 E Cesar Chavez St', 'city' => 'Austin', 'capacity' => 400],
        ])->map(fn (array $venue) => Venue::create($venue));

        $titles = [
            'Vue.js Meetup: Composition API patterns', 'Laravel Live: shipping APIs', 'GraphQL in production',
            'Designing REST APIs that last', 'Frontend Performance Night', 'Testing JavaScript like a pro',
            'PHP 8.4 deep dive', 'Accessibility for engineers', 'Rust for web developers',
            'Intro to TypeScript generics', 'Postgres tips and tricks', 'Remote team rituals',
            'Contract testing with OpenAPI', 'Design systems at scale', 'Serverless Laravel',
            'Building offline-first SPAs', 'Observability 101', 'Women in Tech breakfast',
        ];

        foreach ($titles as $i => $title) {
            $venue = $venues[$i % $venues->count()];
            $startsAt = now()->addDays(3 + $i * 4)->setTime(18, 0);

            $event = Event::factory()->for($organizers[$i % $organizers->count()], 'organizer')->for($venue)->create([
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(3),
                'capacity' => min($venue->capacity, fake()->randomElement([20, 30, 50, 80])),
            ]);

            $event->attendees()->attach($this->pick($attendees, fake()->numberBetween(0, min(18, $event->capacity - 1))));
        }

        // A sold-out event, so "Event is full" can be seen in the UI.
        $full = Event::factory()->for($organizers[0], 'organizer')->for($venues[1])->capacity(12)->create([
            'title' => 'Small-batch coffee cupping (sold out)',
            'starts_at' => now()->addDays(6)->setTime(10, 0),
            'ends_at' => now()->addDays(6)->setTime(12, 0),
        ]);
        $full->attendees()->attach($this->pick($attendees, 12));

        Event::factory()->for($organizers[1], 'organizer')->for($venues[2])->cancelled()->create([
            'title' => 'Cancelled: Sunset JS cruise',
        ]);

        // The demo user organizes one published event and one draft, and attends a few.
        Event::factory()->for($demo, 'organizer')->for($venues[0])->create(['title' => 'Demo User’s API design workshop']);
        Event::factory()->for($demo, 'organizer')->for($venues[3])->draft()->create(['title' => 'Draft: Spring hack night']);

        Event::query()
            ->where('status', EventStatus::Published)
            ->whereKeyNot($full->id)
            ->where('organizer_id', '!=', $demo->id)
            ->orderBy('starts_at')
            ->limit(2)
            ->get()
            ->each(fn (Event $event) => $event->attendees()->syncWithoutDetaching([$demo->id]));
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<int>
     */
    private function pick(Collection $users, int $count): array
    {
        return fake()->randomElements($users->modelKeys(), $count);
    }
}
