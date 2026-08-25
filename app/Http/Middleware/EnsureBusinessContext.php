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
            $suspended = \App\Models\BusinessMembership::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->where('status', \App\Models\BusinessMembership::STATUS_SUSPENDED)
                ->with('business')
                ->first();

            if ($suspended) {
                $bizPaused = $suspended->business?->status === \App\Models\Business::STATUS_SUSPENDED;

                return response()->json([
                    'message' => $bizPaused
                        ? 'This business is paused. Your access is suspended until the owner resumes operations and reactivates you.'
                        : 'Your staff access is suspended. Ask the business owner to reactivate you.',
                    'membership_status' => 'suspended',
                    'business_status' => $suspended->business?->status,
                ], 423);
            }

            return response()->json(['message' => 'You do not have access to this business'], 403);
        }

        try {
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
        } catch (\Throwable $e) {
            report($e);

            // Never block the request with a bare 500 — owners still need hub access.
            $context = new BusinessContext(
                business: $membership->business,
                membership: $membership,
                branch: $membership->defaultBranch,
                permissions: [
                    'business.view',
                    'business.update',
                    'business.members.view',
                    'business.members.create',
                    'business.members.update',
                    'product.view',
                    'product.create',
                    'product.update',
                    'order.view',
                    'order.create',
                    'inventory.view',
                    'payment.view',
                    'report.view',
                ],
            );
        }

        $request->attributes->set('business_context', $context);

        $business = $membership->business;
        if ($business && $business->status === \App\Models\Business::STATUS_SUSPENDED) {
            $path = $request->path();
            $method = strtoupper($request->method());
            $isPauseResume = str_ends_with($path, '/pause') || str_ends_with($path, '/resume');
            $isReadOrMembers = $method === 'GET'
                || str_contains($path, '/members');
            $ownerAllowed = $membership->isOwner() && ($isPauseResume || $isReadOrMembers || $method === 'PUT');

            if (! $ownerAllowed) {
                return response()->json([
                    'message' => 'This business is paused. The owner must resume operations first.',
                    'business_status' => 'suspended',
                ], 423);
            }
        }

        return $next($request);
    }
}
