<?php

use App\Http\Controllers\Api\V1\AttendeeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\VenueController;
use Illuminate\Support\Facades\Route;

/*
| REST API, version 1. The contract is documented in /openapi.yaml at the
| repository root, and every response in the test suite is validated against it.
*/
Route::prefix('v1')->name('v1.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    // Public reads. Authentication is optional and only personalises the response.
    Route::apiResource('events', EventController::class)->only(['index', 'show']);
    Route::apiResource('venues', VenueController::class)->only(['index', 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('me', [MeController::class, 'show'])->name('me.show');
        Route::get('me/registrations', [MeController::class, 'registrations'])->name('me.registrations');

        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::patch('events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
        Route::get('events/{event}/attendees', [AttendeeController::class, 'index'])->name('events.attendees.index');
        Route::put('events/{event}/registration', [RegistrationController::class, 'update'])->name('events.registration.update');
        Route::delete('events/{event}/registration', [RegistrationController::class, 'destroy'])->name('events.registration.destroy');

        Route::apiResource('venues', VenueController::class)->only(['store']);
    });
});
