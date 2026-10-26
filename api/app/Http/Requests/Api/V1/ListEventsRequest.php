<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Rules\Iso8601DateTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEventsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = collect(Event::SORTABLE)->flatMap(fn (string $column) => [$column, "-{$column}"])->all();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'city' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', new Iso8601DateTime, 'date'],
            'to' => ['sometimes', new Iso8601DateTime, 'date', 'after_or_equal:from'],
            'venue_id' => ['sometimes', 'integer'],
            'organizer_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(EventStatus::class)],
            'has_spots' => ['sometimes', 'in:true,false,1,0'],
            'sort' => ['sometimes', Rule::in($sorts)],
            // A change feed is always ordered by updated_at, so it can't also be sorted.
            'updated_since' => ['sometimes', new Iso8601DateTime, 'date', 'prohibits:sort'],
            'pagination' => ['sometimes', Rule::in(['offset', 'cursor'])],
            'cursor' => ['sometimes', 'string', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Cursor pagination is opt-in (`pagination=cursor`, or a `cursor` from a previous
     * page) and always used for the updated_since feed, where page offsets would skip
     * events that move to the end when they change.
     */
    public function usesCursor(): bool
    {
        return $this->has('updated_since') || $this->has('cursor') || $this->input('pagination') === 'cursor';
    }

    /**
     * The validated query, in the shape Event::filter() expects.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            ...$this->safe()->except(['page', 'per_page', 'has_spots', 'pagination', 'cursor']),
            'has_spots' => $this->boolean('has_spots'),
        ];
    }
}
