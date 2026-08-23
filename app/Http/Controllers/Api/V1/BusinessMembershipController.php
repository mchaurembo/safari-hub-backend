<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\MembershipRole;
use App\Models\Position;
use App\Models\User;
use App\Services\BusinessService;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BusinessMembershipController extends Controller
{
    public function __construct(
        private readonly BusinessService $businessService,
    ) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');

        if (! $context->can('business.members.view')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $members = BusinessMembership::query()
            ->where('business_id', $business->id)
            ->with(['user:id,name,email,phone,status', 'role', 'position', 'defaultBranch'])
            ->orderBy('id')
            ->get()
            ->map(fn (BusinessMembership $m) => $this->presentMember($m));

        return response()->json(['data' => $members]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');

        if (! $context->can('business.members.create')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'membership_role_id' => 'required|integer|exists:membership_roles,id',
            'position_id' => 'nullable|integer|exists:positions,id',
            'password' => 'nullable|string|min:8',
        ]);

        $role = MembershipRole::findOrFail($data['membership_role_id']);
        if ($role->scope !== MembershipRole::SCOPE_BUSINESS) {
            return response()->json(['message' => 'Invalid role for business membership'], 422);
        }

        if ($role->code === MembershipRole::CODE_OWNER && ! $context->membership->isOwner()) {
            return response()->json(['message' => 'Only owners can assign owner role'], 403);
        }

        $employee = User::where('email', $data['email'])->first();

        if (! $employee) {
            $employee = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? Str::random(16),
                'status' => 'active',
            ]);
        }

        $membership = $this->businessService->addMember(
            business: $business,
            employee: $employee,
            role: $role,
            positionId: $data['position_id'] ?? null,
            invitedBy: $context->membership,
        );

        $membership->load(['user:id,name,email,phone,status', 'role', 'position']);

        return response()->json([
            'message' => 'Member added',
            'data' => $this->presentMember($membership),
            'existing_user' => User::where('email', $data['email'])->where('id', '!=', $employee->id)->exists(),
        ], 201);
    }

    public function update(Request $request, Business $business, BusinessMembership $membership): JsonResponse
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');

        if ((int) $membership->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Membership not found'], 404);
        }

        if (! $context->can('business.members.update')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'status' => 'sometimes|in:active,suspended,terminated',
            'membership_role_id' => 'sometimes|integer|exists:membership_roles,id',
            'position_id' => 'nullable|integer|exists:positions,id',
            'default_branch_id' => 'nullable|integer|exists:business_branches,id',
        ]);

        if (isset($data['membership_role_id'])) {
            $role = MembershipRole::findOrFail($data['membership_role_id']);
            if ($role->code === MembershipRole::CODE_OWNER && ! $context->membership->isOwner()) {
                return response()->json(['message' => 'Only owners can assign owner role'], 403);
            }
        }

        if (($data['status'] ?? null) === BusinessMembership::STATUS_TERMINATED) {
            $data['terminated_at'] = now();
        }

        $membership->update($data);
        $membership->load(['user:id,name,email,phone,status', 'role', 'position', 'defaultBranch']);

        return response()->json([
            'message' => 'Membership updated',
            'data' => $this->presentMember($membership),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentMember(BusinessMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'uuid' => $membership->uuid,
            'status' => $membership->status,
            'role' => $membership->role?->only(['id', 'code', 'name']),
            'position' => $membership->position?->only(['id', 'code', 'name']),
            'default_branch_id' => $membership->default_branch_id,
            'user' => $membership->user,
            'accepted_at' => $membership->accepted_at,
            'terminated_at' => $membership->terminated_at,
        ];
    }
}
