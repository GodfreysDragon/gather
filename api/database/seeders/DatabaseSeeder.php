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
        // Only admins can add venues.
        User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@gather.test']);
        $organizers = User::factory(4)->create();
        $attendees = User::factory(40)->create();

        $venues = collect([
            ['name' => 'Factory Hall', 'address' => 'Lohmühlenstraße 65', 'city' => 'Berlin', 'capacity' => 300],
            ['name' => 'Kreuzberg Loft', 'address' => 'Skalitzer Str. 85', 'city' => 'Berlin', 'capacity' => 60],
            ['name' => 'LX Factory Stage', 'address' => 'R. Rodrigues de Faria 103', 'city' => 'Lisbon', 'capacity' => 200],
            ['name' => 'Distillery Commons', 'address' => '55 Mill St', 'city' => 'Toronto', 'capacity' => 150],
            ['name' => 'Eastside Warehouse', 'address' => '2400 E Cesar Chavez St', 'city' => 'Austin', 'capacity' => 400],
        ])->map(fn (array $venue) => Venue::create($venue));

        $events = [
            'Vue.js Meetup: Composition API patterns' => 'Three short talks on composables that scale: sharing state without a store, async data with cancellation, and testing composables in isolation. Pizza after.',
            'Laravel Live: shipping APIs' => 'From route to production: versioning, problem details, rate limits and contract tests, with a live refactor of a real endpoint.',
            'GraphQL in production' => 'Query cost limits, persisted queries, caching and the errors nobody warns you about. Bring your war stories.',
            'Designing REST APIs that last' => 'Resource modelling, idempotency, pagination and evolving a public API without breaking the clients you never see.',
            'Frontend Performance Night' => 'Core Web Vitals in practice: what moves INP, how to find long tasks, and a teardown of a slow production page.',
            'Testing JavaScript like a pro' => 'Unit, component and end-to-end tests that catch real bugs and survive refactors. Hands-on with Vitest and Playwright.',
            'PHP 8.4 deep dive' => 'Property hooks, asymmetric visibility, lazy objects and the new array functions, with examples from real codebases.',
            'Accessibility for engineers' => 'Keyboard paths, focus management, ARIA you actually need and screen reader testing on a budget.',
            'Rust for web developers' => 'Ownership explained with HTTP handlers, plus where Rust earns its place next to a JavaScript or PHP stack.',
            'Intro to TypeScript generics' => 'From identity functions to typed API clients: constraints, inference and conditional types without the headache.',
            'Postgres tips and tricks' => 'Indexes you are missing, EXPLAIN without fear, row locking and the JSONB features worth knowing.',
            'Remote team rituals' => 'An open discussion on async decision-making, written culture and keeping a distributed team connected.',
            'Contract testing with OpenAPI' => 'Make the spec the source of truth: validate every response in your test suite and generate typed clients from it.',
            'Design systems at scale' => 'Tokens, component APIs and governance: how three teams keep one design system healthy across many products.',
            'Serverless Laravel' => 'Running Laravel on functions: cold starts, queues, file storage and when serverless is the wrong answer.',
            'Building offline-first SPAs' => 'Service workers, IndexedDB and sync strategies for apps that keep working on a train.',
            'Observability 101' => 'Logs, metrics and traces explained with one failing request followed end to end through a real system.',
            'Women in Tech breakfast' => 'Coffee, pastries and conversation. An informal morning to meet other women working in tech. Everyone welcome.',
        ];

        foreach (array_keys($events) as $i => $title) {
            $venue = $venues[$i % $venues->count()];
            $startsAt = now()->addDays(3 + $i * 4)->setTime(18, 0);

            $event = Event::factory()->for($organizers[$i % $organizers->count()], 'organizer')->for($venue)->create([
                'title' => $title,
                'description' => $events[$title],
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(3),
                'capacity' => min($venue->capacity, fake()->randomElement([20, 30, 50, 80])),
            ]);

            $event->attendees()->attach($this->pick($attendees, fake()->numberBetween(0, min(18, $event->capacity - 1))));
        }

        // A sold-out event, so "Event is full" can be seen in the UI.
        $full = Event::factory()->for($organizers[0], 'organizer')->for($venues[1])->capacity(12)->create([
            'title' => 'Small-batch coffee cupping (sold out)',
            'description' => 'Taste six single-origin coffees with a local roaster and learn how cupping scores are made. Only twelve seats.',
            'starts_at' => now()->addDays(6)->setTime(10, 0),
            'ends_at' => now()->addDays(6)->setTime(12, 0),
        ]);
        $full->attendees()->attach($this->pick($attendees, 12));

        Event::factory()->for($organizers[1], 'organizer')->for($venues[2])->cancelled()->create([
            'title' => 'Cancelled: Sunset JS cruise',
            'starts_at' => now()->addDays(16)->setTime(19, 0),
            'ends_at' => now()->addDays(16)->setTime(22, 0),
            'description' => 'Lightning talks on the river at sunset. Cancelled because of the weather forecast; we will be back in spring.',
        ]);

        // The demo user organizes one published event and one draft, and attends a few.
        Event::factory()->for($demo, 'organizer')->for($venues[0])->create([
            'title' => 'Demo User’s API design workshop',
            'starts_at' => now()->addDays(55)->setTime(9, 0),
            'ends_at' => now()->addDays(55)->setTime(13, 0),
            'description' => 'A half-day workshop: design a small REST API from a user story, write its OpenAPI contract and review each other\'s designs.',
        ]);
        Event::factory()->for($demo, 'organizer')->for($venues[3])->draft()->create([
            'title' => 'Draft: Spring hack night',
            'starts_at' => now()->addDays(80)->setTime(18, 0),
            'ends_at' => now()->addDays(80)->setTime(22, 0),
            'description' => 'An evening of building whatever you like, with mentors on hand. Details to follow.',
        ]);

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
