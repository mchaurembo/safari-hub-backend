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

    // Prevent sticky browser/CDN caches of SPA shell (old asset hashes).
    return response()->file($index, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->where('any', '^(?!api(?:/|$)|up(?:/|$)|storage(?:/|$)).*');
