<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessType;
use App\Services\BusinessService;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessService $businessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessIds = BusinessMembership::query()
            ->where('user_id', $request->user()->id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->pluck('business_id');

        $businesses = Business::query()
            ->whereIn('id', $businessIds)
            ->with(['category', 'type', 'profile', 'branches', 'capabilityAssignments.capability'])
            ->orderBy('trade_name')
            ->get()
            ->map(fn (Business $b) => $this->presentBusiness($b));

        return response()->json(['data' => $businesses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_type_id' => 'required|integer|exists:business_types,id',
            'legal_name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'branch_name' => 'nullable|string|max:255',
        ]);

        $type = BusinessType::findOrFail($data['business_type_id']);
        $business = $this->businessService->register($request->user(), $type, $data);

        return response()->json([
            'message' => 'Business registered',
            'data' => $this->presentBusiness($business),
        ], 201);
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');

        if (! $context->can('business.view')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $business->load(['category', 'type', 'profile', 'branches', 'capabilityAssignments.capability']);

        return response()->json(['data' => $this->presentBusiness($business)]);
    }

    /** @return array<string, mixed> */
    private function presentBusiness(Business $business): array
    {
        return [
            'id' => $business->id,
            'uuid' => $business->uuid,
            'legal_name' => $business->legal_name,
            'trade_name' => $business->trade_name,
            'name' => $business->displayName(),
            'slug' => $business->slug,
            'status' => $business->status,
            'verification_status' => $business->verification_status,
            'category' => $business->category?->only(['id', 'code', 'name']),
            'type' => $business->type?->only(['id', 'code', 'name']),
            'profile' => $business->profile,
            'branches' => $business->branches,
            'capabilities' => $business->capabilityAssignments
                ->where('enabled', true)
                ->map(fn ($a) => $a->capability?->code)
                ->filter()
                ->values(),
            'legacy_transport_owner_id' => $business->legacy_transport_owner_id,
            'legacy_garage_id' => $business->legacy_garage_id,
        ];
    }
}
