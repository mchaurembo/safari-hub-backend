<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesBusinessContext;
use App\Models\Garage;
use App\Models\ServiceHistory;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\GarageWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    use ResolvesBusinessContext;

    public function __construct(private GarageWorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $garage = $this->resolveGarage($user);
        if (! $garage) {
            return response()->json(['message' => 'No garage profile found.'], 404);
        }

        $query = WorkOrder::with([
            'booking:id,status,scheduled_at,vehicle_reg',
            'service:id,name',
            'technician.user:id,name',
            'customer:id,name',
        ])->where('garage_id', $garage->id);

        if ($businessId = $this->activeBusinessId($request)) {
            $query->where('business_id', $businessId);
        }

        if ($user->isGarageTechnician($garage) && ! $user->ownsGarage($garage)) {
            $tech = Technician::where('user_id', $user->id)->where('garage_id', $garage->id)->first();
            $query->where('technician_id', $tech?->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function show(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        return response()->json([
            'work_order' => $workOrder->load([
                'items',
                'booking',
                'service',
                'technician.user:id,name',
                'customer:id,name,email,phone',
                'serviceHistory',
            ]),
        ]);
    }

    public function start(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('update', $workOrder);

        if (! in_array($workOrder->status, ['open', 'assigned', 'diagnosing', 'waiting_approval'], true)) {
            return response()->json(['message' => 'Work order cannot be started from status: '.$workOrder->status], 422);
        }

        return response()->json([
            'work_order' => $this->workflow->start($workOrder),
            'message' => 'Work order started',
        ]);
    }

    public function complete(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('complete', $workOrder);

        $validated = $request->validate([
            'diagnosis' => 'nullable|string|max:5000',
        ]);

        if (! in_array($workOrder->status, ['in_progress', 'quality_check', 'assigned'], true)) {
            return response()->json(['message' => 'Complete only from in-progress (or quality check).'], 422);
        }

        return response()->json([
            'work_order' => $this->workflow->complete($workOrder, $validated['diagnosis'] ?? null),
            'message' => 'Work order completed and service history recorded',
        ]);
    }

    public function addItem(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('update', $workOrder);

        $validated = $request->validate([
            'item_type' => 'required|in:labour,part,other',
            'description' => 'required|string|max:500',
            'quantity' => 'nullable|numeric|min:0.01',
            'unit_price' => 'nullable|numeric|min:0',
        ]);

        $item = $this->workflow->addItem($workOrder, $validated);

        return response()->json([
            'item' => $item,
            'work_order' => $workOrder->fresh(['items']),
        ], 201);
    }

    /** Vehicle-centric service history (by registration). */
    public function serviceHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_reg' => 'required|string|max:50',
        ]);

        $reg = trim($validated['vehicle_reg']);
        $user = $request->user();

        $query = ServiceHistory::query()
            ->where('vehicle_reg', $reg)
            ->latest('serviced_at');

        // Restrict: customer own history, garage staff for their garage, admin all
        if (! $user->hasCapability('admin')) {
            $garageIds = Garage::where('owner_id', $user->id)->pluck('id')
                ->merge(
                    $user->garageMemberships()->where('status', 'active')->pluck('garage_id')
                )
                ->unique()
                ->values();

            $query->where(function ($q) use ($user, $garageIds) {
                $q->where('customer_id', $user->id);
                if ($garageIds->isNotEmpty()) {
                    $q->orWhereIn('garage_id', $garageIds);
                }
            });
        }

        return response()->json([
            'vehicle_reg' => $reg,
            'history' => $query->with(['garage:id,name'])->limit(50)->get(),
        ]);
    }

    private function resolveGarage($user): ?Garage
    {
        $garage = Garage::where('owner_id', $user->id)->first();
        if ($garage) {
            return $garage;
        }

        $membership = $user->garageMemberships()
            ->where('status', 'active')
            ->whereNull('left_at')
            ->with('garage')
            ->latest('id')
            ->first();

        return $membership?->garage
            ?? Technician::where('user_id', $user->id)->first()?->garage;
    }
}
