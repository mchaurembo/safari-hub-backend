<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessMembership;
use App\Services\BusinessAuthorizationService;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessContextController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorizationService $authorization,
    ) {}

    public function memberships(Request $request): JsonResponse
    {
        $memberships = BusinessMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->with(['business.type', 'business.category', 'role', 'position', 'defaultBranch'])
            ->orderBy('id')
            ->get()
            ->map(fn (BusinessMembership $m) => $this->presentMembership($m));

        return response()->json(['data' => $memberships]);
    }

    public function show(Request $request): JsonResponse
    {
        $context = $this->authorization->currentContext($request->user());

        return response()->json([
            'data' => $context?->toArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_id' => 'required|integer|exists:businesses,id',
            'branch_id' => 'nullable|integer|exists:business_branches,id',
        ]);

        $membership = $this->authorization->resolveMembership($request->user(), (int) $data['business_id']);
        if (! $membership) {
            return response()->json(['message' => 'You do not have access to this business'], 403);
        }

        $branch = null;
        if (! empty($data['branch_id'])) {
            $branch = $membership->business->branches()->find($data['branch_id']);
            if (! $branch) {
                return response()->json(['message' => 'Branch not found for this business'], 422);
            }
        }

        $context = new BusinessContext(
            business: $membership->business,
            membership: $membership,
            branch: $branch ?? $membership->defaultBranch,
            permissions: $this->authorization->effectivePermissions($membership),
        );

        $this->authorization->storeContext($request->user(), $context);

        return response()->json(['data' => $context->toArray()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->authorization->clearContext($request->user());

        return response()->json(['message' => 'Business context cleared']);
    }

    /** @return array<string, mixed> */
    private function presentMembership(BusinessMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'uuid' => $membership->uuid,
            'status' => $membership->status,
            'role' => $membership->role?->code,
            'role_name' => $membership->role?->name,
            'position' => $membership->position?->code,
            'position_name' => $membership->position?->name,
            'business' => [
                'id' => $membership->business->id,
                'uuid' => $membership->business->uuid,
                'name' => $membership->business->displayName(),
                'status' => $membership->business->status,
                'type' => $membership->business->type?->code,
                'category' => $membership->business->category?->code,
                'legacy_transport_owner_id' => $membership->business->legacy_transport_owner_id,
                'legacy_garage_id' => $membership->business->legacy_garage_id,
            ],
            'default_branch_id' => $membership->default_branch_id,
        ];
    }
}
