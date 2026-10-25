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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * The validated query, in the shape Event::filter() expects.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            ...$this->safe()->except(['page', 'per_page', 'has_spots']),
            'has_spots' => $this->boolean('has_spots'),
        ];
    }
}
