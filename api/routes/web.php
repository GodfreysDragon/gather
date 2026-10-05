<?php

use Illuminate\Support\Facades\Route;

/*
| This application has no web UI; the SPA lives in ../web and talks to the API over HTTP.
| The root URL just points API consumers at the entry points.
*/
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'rest' => url('/api/v1'),
    'graphql' => url('/graphql'),
    'openapi' => url('/openapi.yaml'),
]));

Route::get('/openapi.yaml', fn () => response()->file(base_path('../openapi.yaml'), [
    'Content-Type' => 'application/yaml',
]));
