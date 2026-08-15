<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Serve the React SPA from public/index.html
|--------------------------------------------------------------------------
|
| After running scripts/prepare-for-cloudways.sh, the Vite build lives in
| public/ (index.html + assets/). API routes stay under /api/*.
|
*/
Route::get('/{any?}', function () {
    $index = public_path('index.html');

    if (! file_exists($index)) {
        return response(
            'Frontend haijajengwa. Endesha: ./scripts/prepare-for-cloudways.sh',
            503
        )->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    return response()->file($index);
})->where('any', '^(?!api(?:/|$)|up(?:/|$)|storage(?:/|$)).*');
