<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VenueResource;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class VenueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $venues = Venue::query()
            ->when($request->string('city')->toString(), fn ($query, string $city) => $query->where('city', $city))
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        return VenueResource::collection($venues);
    }

    public function store(Request $request): JsonResponse
    {
        $venue = Venue::create($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]));

        return (new VenueResource($venue))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('v1.venues.show', $venue));
    }

    public function show(Venue $venue): VenueResource
    {
        return new VenueResource($venue);
    }
}
