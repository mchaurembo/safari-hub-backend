<?php

namespace App\Http\Middleware;

use App\Support\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireBusinessPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var BusinessContext|null $context */
        $context = $request->attributes->get('business_context');

        if (! $context instanceof BusinessContext) {
            return response()->json(['message' => 'Business context missing'], 422);
        }

        if (! $context->can($permission)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
