<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldForceHttps()) {
            return $next($request);
        }

        if (! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }

    private function shouldForceHttps(): bool
    {
        if (app()->environment('local', 'testing')) {
            return false;
        }

        $appUrl = (string) config('app.url', '');

        return str_starts_with($appUrl, 'https://')
            || filter_var(env('FORCE_HTTPS', false), FILTER_VALIDATE_BOOL);
    }
}
