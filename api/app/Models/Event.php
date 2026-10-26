<?php

namespace App\Models;

use App\Enums\EventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['venue_id', 'title', 'description', 'starts_at', 'ends_at', 'capacity', 'status'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Sort keys accepted by `sort` (prefix with "-" for descending).
     * Shared by the REST query string and the GraphQL `sort` argument.
     */
    public const SORTABLE = ['starts_at', 'title', 'created_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'published',
    ];

    /** @return BelongsTo<User, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return HasMany<Registration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'registrations')->withTimestamps();
    }

    public function registrationCount(): int
    {
        return $this->registrations_count ?? $this->registrations()->count();
    }

    public function spotsLeft(): int
    {
        return max(0, $this->capacity - $this->registrationCount());
    }

    public function isRegistered(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (array_key_exists('viewer_registered', $this->attributes)) {
            return (bool) $this->viewer_registered;
        }

        return $this->registrations()->where('user_id', $user->id)->exists();
    }

    /**
     * Preload everything the API representations need in a constant number of queries:
     * the registration count, and whether the viewer is registered.
     */
    #[Scope]
    protected function withApiCounts(Builder $query, ?User $viewer = null): void
    {
        $query->withCount('registrations')->when($viewer, fn (Builder $q) => $q->withExists([
            'registrations as viewer_registered' => fn (Builder $r) => $r->where('user_id', $viewer->id),
        ]));
    }

    /**
     * The same preloading for an event that has already been fetched.
     */
    public function loadApiCounts(?User $viewer = null): static
    {
        $this->loadCount('registrations');

        if ($viewer !== null) {
            $this->setAttribute('viewer_registered', $this->registrations()->where('user_id', $viewer->id)->exists());
            $this->syncOriginalAttribute('viewer_registered');
        }

        return $this;
    }

    /**
     * Apply the listing filters. The REST controller and the GraphQL `events` field
     * both call this, so the two APIs can never disagree about what a filter means.
     *
     * `updated_since` turns the listing into a change feed: everything changed at or after
     * that instant, oldest change first, including deleted events as tombstones.
     *
     * @param  array{q?: ?string, city?: ?string, from?: ?string, to?: ?string, venue_id?: int|string|null,
     *               organizer_id?: int|string|null, status?: ?string, has_spots?: ?bool, sort?: ?string,
     *               updated_since?: ?string}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters, ?User $viewer = null): void
    {
        $query
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                // Treat % and _ as literal characters. SQLite has no default escape character,
                // so one is spelled out; "!" needs no quoting in any of the supported databases.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("title like ? escape '!'", [$like])
                    ->orWhereRaw("description like ? escape '!'", [$like]));
            })
            ->when($filters['city'] ?? null, fn (Builder $q, string $city) => $q->whereRelation('venue', 'city', $city))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('starts_at', '>=', CarbonImmutable::parse($from)->utc()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('starts_at', '<=', CarbonImmutable::parse($to)->utc()))
            ->when($filters['venue_id'] ?? null, fn (Builder $q, $id) => $q->where('venue_id', $id))
            ->when($filters['organizer_id'] ?? null, fn (Builder $q, $id) => $q->where('organizer_id', $id))
            // "Has spots" means bookable, so drafts and cancelled events never qualify however empty they are.
            ->when($filters['has_spots'] ?? false, fn (Builder $q) => $q
                ->where('status', EventStatus::Published->value)
                ->whereRaw('(select count(*) from registrations where registrations.event_id = events.id) < events.capacity'));

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        // Published and cancelled events are public; drafts are only visible to their organizer.
        $query->where(fn (Builder $q) => $q
            ->where('status', '!=', EventStatus::Draft->value)
            ->when($viewer, fn (Builder $q) => $q->orWhere('organizer_id', $viewer->id)));

        if ($since = $filters['updated_since'] ?? null) {
            $query->withTrashed()
                ->where('updated_at', '>=', CarbonImmutable::parse($since)->utc())
                ->orderBy('updated_at')
                ->orderBy('id');

            return;
        }

        $sort = $filters['sort'] ?? 'starts_at';
        $column = ltrim($sort, '-');

        $query->orderBy(
            in_array($column, self::SORTABLE, true) ? $column : 'starts_at',
            str_starts_with($sort, '-') ? 'desc' : 'asc',
        )->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'status' => EventStatus::class,
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
