<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
|--------------------------------------------------------------------------
| Favicon — explicit routes so SPA catch-all never serves HTML as icon
|--------------------------------------------------------------------------
*/
$sendIcon = function (string $path, string $mime): BinaryFileResponse {
    $full = public_path($path);
    abort_unless(is_file($full), 404);

    return response()->file($full, [
        'Content-Type' => $mime,
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
};

Route::get('/favicon.ico', fn () => $sendIcon('favicon.ico', 'image/x-icon'));
Route::get('/favicon.svg', fn () => $sendIcon('favicon.svg', 'image/svg+xml'));
Route::get('/favicon.png', fn () => $sendIcon('favicon.png', 'image/png'));
Route::get('/favicon-16.png', fn () => $sendIcon('favicon-16.png', 'image/png'));
Route::get('/favicon-32.png', fn () => $sendIcon('favicon-32.png', 'image/png'));
Route::get('/brand/icon.ico', fn () => $sendIcon('brand/icon.ico', 'image/x-icon'));
Route::get('/brand/icon-32.png', fn () => $sendIcon('brand/icon-32.png', 'image/png'));
Route::get('/brand/icon-180.png', fn () => $sendIcon('brand/icon-180.png', 'image/png'));

/*
|--------------------------------------------------------------------------
| Serve the React SPA from public/index.html
|--------------------------------------------------------------------------
*/
Route::get('/{any?}', function () {
    $index = public_path('index.html');

    if (! file_exists($index)) {
        return response(
            'Frontend haijajengwa. Endesha: ./scripts/prepare-for-cloudways.sh',
            503
        )->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    return response()->file($index, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->where('any', '^(?!api(?:/|$)|up(?:/|$)|storage(?:/|$)|assets(?:/|$)|brand(?:/|$)|favicon).*$');
