<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CargoRequest;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\TransportOwner;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    public function availableDrivers(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) {
            return response()->json(['message' => 'Not a transport owner'], 403);
        }

        $search = $request->input('search', '');

        // Find users with driver role who are NOT already in this owner's fleet
        $existingDriverUserIds = Driver::where('owner_id', $owner->id)->pluck('user_id');

        $driverRoleId = \App\Models\Role::where('name', 'driver')->value('id');

        $users = \App\Models\User::where('role_id', $driverRoleId)
            ->where('status', 'active')
            ->whereNotIn('id', $existingDriverUserIds)
            ->where(function ($q) use ($search) {
                if ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                }
            })
            ->select('id', 'name', 'email', 'phone')
            ->limit(20)
            ->get();

        return response()->json(['data' => $users]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'license_number' => 'required|string|max:100',
            'address' => 'nullable|string',
        ]);

        $owner = TransportOwner::updateOrCreate(
            ['user_id' => $request->user()->id],
            array_merge($validated, ['status' => 'pending'])
        );

        return response()->json(['data' => $owner, 'message' => 'Profile saved. Awaiting admin approval.']);
    }

    public function vehicles(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) {
            return response()->json(['message' => 'Not a transport owner'], 403);
        }

        $vehicles = Vehicle::with('drivers.user')->where('owner_id', $owner->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $vehicles]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) {
            return response()->json(['message' => 'Not a transport owner'], 403);
        }

        $drivers = Driver::with('user')->where('owner_id', $owner->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $drivers]);
    }

    public function trips(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) {
            return response()->json(['message' => 'Not a transport owner'], 403);
        }

        $trips = \App\Models\Trip::with(['route', 'vehicle', 'driver.user'])
            ->whereHas('vehicle', fn($q) => $q->where('owner_id', $owner->id))
            ->orderByDesc('departure_time')
            ->get();

        return response()->json(['data' => $trips]);
    }

    // GET /owner/cargo-trips — all cargo requests for this owner's drivers
    public function cargoTrips(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) {
            return response()->json(['message' => 'Not a transport owner'], 403);
        }

        $driverIds = Driver::where('owner_id', $owner->id)->pluck('id');

        $trips = CargoRequest::with(['customer:id,name,phone', 'driver.user:id,name,phone', 'vehicle:id,vehicle_number,vehicle_type,model'])
            ->whereIn('driver_id', $driverIds)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $trips]);
    }

    // GET /owner/earnings — money collected from completed cargo trips, per driver
    public function earnings(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) {
            return response()->json(['message' => 'Not a transport owner'], 403);
        }

        $driverIds = Driver::where('owner_id', $owner->id)->pluck('id');

        // Count earnings from all trips that have a confirmed price
        // (accepted = customer agreed, in_progress = trip started, delivered = arrived, completed = confirmed)
        $completed = CargoRequest::with(['driver.user:id,name', 'vehicle:id,vehicle_number,vehicle_type'])
            ->whereIn('driver_id', $driverIds)
            ->whereIn('status', ['accepted', 'in_progress', 'delivered', 'completed'])
            ->whereNotNull('quoted_price')
            ->get();

        $totalEarnings = $completed->sum('quoted_price');

        // Per-driver breakdown
        $byDriver = $completed->groupBy('driver_id')->map(function ($requests, $driverId) {
            $first = $requests->first();
            return [
                'driver_id'   => $driverId,
                'driver_name' => $first->driver?->user?->name ?? 'Unknown',
                'trips'       => $requests->count(),
                'total'       => $requests->sum('quoted_price'),
                'last_trip'   => $requests->max('updated_at'),
            ];
        })->values();

        // Monthly breakdown (last 6 months)
        $monthly = $completed->groupBy(fn($r) => \Carbon\Carbon::parse($r->updated_at)->format('Y-m'))
            ->map(fn($requests, $month) => [
                'month'  => $month,
                'trips'  => $requests->count(),
                'total'  => $requests->sum('quoted_price'),
            ])
            ->sortKeys()
            ->values();

        return response()->json([
            'data' => [
                'total_earnings' => (float) $totalEarnings,
                'completed_trips' => $completed->count(),
                'by_driver'      => $byDriver,
                'monthly'        => $monthly,
            ],
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) {
            return response()->json(['message' => 'Not a transport owner'], 403);
        }

        try {
            $vehicleIds = Vehicle::where('owner_id', $owner->id)->pluck('id');
            $tripIds = \App\Models\Trip::whereIn('vehicle_id', $vehicleIds)->pluck('id');
            $bookingIds = Booking::whereIn('trip_id', $tripIds)
                ->whereIn('status', ['paid', 'completed'])
                ->pluck('id');

            $revenue = Payment::whereIn('booking_id', $bookingIds)
                ->where('status', 'completed')
                ->sum('amount');
        } catch (\Exception $e) {
            $revenue = 0;
        }

        return response()->json(['data' => ['total_revenue' => (float) $revenue]]);
    }
}
