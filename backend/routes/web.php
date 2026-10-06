<?php

use Illuminate\Support\Facades\Route;

/*
| The dashboard is a single-page application. In production its built assets
| live in public/ and every non-API path returns index.html so client-side
| routing works on refresh.
*/
Route::get('/{any?}', function () {
    $index = public_path('index.html');
    if (! is_file($index)) {
        return response('The dashboard has not been built. Run "npm run build" in frontend/ (see README).', 503);
    }

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('any', '^(?!api/|sanctum/|up$).*$');
