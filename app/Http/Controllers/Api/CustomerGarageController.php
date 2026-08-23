<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Garage;
use App\Models\GarageBooking;
use App\Models\GarageService;
use App\Services\GarageWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Customer marketplace for garage services (same CHAPA customer account as transport).
 */
class CustomerGarageController extends Controller
{
    public function __construct(
        private readonly GarageWorkflowService $workflow,
    ) {}

    /** Browse active garages (with service counts). */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $query = Garage::query()
            ->where('status', 'active')
            ->withCount(['services as services_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->limit(50)->get(['id', 'name', 'location', 'status']),
        ]);
    }

    /** Garage detail + bookable services. */
    public function show(Garage $garage): JsonResponse
    {
        if ($garage->status !== 'active') {
            return response()->json(['message' => 'Garage is not available'], 404);
        }

        $services = GarageService::query()
            ->where('garage_id', $garage->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'price', 'type', 'duration_minutes', 'status']);

        return response()->json([
            'data' => [
                'garage' => $garage->only(['id', 'name', 'location', 'status']),
                'services' => $services,
            ],
        ]);
    }

    /** Customer's garage bookings. */
    public function bookings(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->input('status');

        $query = GarageBooking::query()
            ->where('customer_id', $user->id)
            ->with(['garage:id,name,location', 'service:id,name,price,type', 'technician.user:id,name']);

        if ($status) {
            $query->where('status', $status);
        }

        $perPage = min(50, max(1, (int) $request->integer('per_page', 20)));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    /** Customer self-serve book a garage service. */
    public function storeBooking(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->enrollCapability('customer');

        $validated = $request->validate([
            'garage_id' => 'required|exists:garages,id',
            'service_id' => 'required|integer',
            'vehicle_reg' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $garage = Garage::findOrFail($validated['garage_id']);
        if ($garage->status !== 'active') {
            return response()->json(['message' => 'Garage is not accepting bookings'], 422);
        }

        $service = GarageService::query()
            ->where('id', $validated['service_id'])
            ->where('garage_id', $garage->id)
            ->where('status', 'active')
            ->first();

        if (! $service) {
            return response()->json(['message' => 'Service is not available at this garage'], 422);
        }

        try {
            $booking = GarageBooking::create([
                'customer_id' => $user->id,
                'garage_id' => $garage->id,
                'service_id' => $service->id,
                'technician_id' => null,
                'vehicle_reg' => $validated['vehicle_reg'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'amount' => $service->price,
                'status' => 'pending',
                'scheduled_at' => $validated['scheduled_at'] ?? now()->addDay(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Customer garage booking failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not create booking.'], 500);
        }

        $workOrder = $this->workflow->syncFromBooking($booking);

        return response()->json([
            'data' => [
                'booking' => $booking->load(['garage:id,name,location', 'service', 'workOrder']),
                'work_order' => $workOrder,
                'user' => \App\Support\AuthUserPresenter::present($user->fresh()),
            ],
            'message' => 'Garage booking created',
        ], 201);
    }
}
