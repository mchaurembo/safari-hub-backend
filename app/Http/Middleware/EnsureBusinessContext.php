<?php

namespace App\Http\Middleware;

use App\Services\BusinessAuthorizationService;
use App\Support\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessContext
{
    public function __construct(
        private readonly BusinessAuthorizationService $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $businessId = (int) ($request->route('business')?->id
            ?? $request->route('business')
            ?? $request->header('X-Business-Id')
            ?? 0);

        if (! $businessId) {
            $context = $this->authorization->currentContext($user);
            if (! $context) {
                return response()->json([
                    'message' => 'Business context required. Set X-Business-Id or POST /api/v1/me/context.',
                ], 422);
            }
            $request->attributes->set('business_context', $context);

            return $next($request);
        }

        $membership = $this->authorization->resolveMembership($user, $businessId);
        if (! $membership) {
            return response()->json(['message' => 'You do not have access to this business'], 403);
        }

        $branchId = $request->header('X-Branch-Id');
        $branch = $branchId
            ? $membership->business->branches()->find((int) $branchId)
            : $membership->defaultBranch;

        $context = new BusinessContext(
            business: $membership->business,
            membership: $membership,
            branch: $branch,
            permissions: $this->authorization->effectivePermissions($membership),
        );

        $request->attributes->set('business_context', $context);

        return $next($request);
    }
}
