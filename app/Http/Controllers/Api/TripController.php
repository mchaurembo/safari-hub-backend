<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesTransportFleet;
use App\Models\Booking;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    use ResolvesTransportFleet;

    public function index(Request $request): JsonResponse
    {
        $query = Trip::with(['route', 'vehicle.owner', 'driver.user', 'business:id,trade_name,legal_name,logo_url'])
            ->where('status', 'scheduled');

        if ($request->filled('business_id')) {
            $query->forBusiness((int) $request->business_id);
        }
        if ($request->route_id) {
            $query->where('route_id', $request->route_id);
        }
        if ($request->date) {
            $query->whereDate('departure_time', $request->date);
        }

        $trips = $query->orderBy('departure_time')->get();

        return response()->json(['data' => $trips]);
    }

    public function show(Trip $trip): JsonResponse
    {
        $trip->load(['route', 'vehicle.owner', 'driver.user', 'tripStops', 'business:id,trade_name,legal_name,logo_url']);

        return response()->json(['data' => $trip]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $owner = $this->transportFleet($request);
        if (!$owner || $owner->status !== 'approved') {
            return response()->json(['message' => 'Fleet not approved'], 403);
        }

        $validated = $request->validate([
            'route_id' => 'required|exists:routes,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id' => 'required|exists:drivers,id',
            'departure_time' => 'required|date',
            'arrival_time' => 'required|date|after:departure_time',
            'price' => 'required|numeric|min:0',
        ]);

        $vehicle = \App\Models\Vehicle::where('id', $validated['vehicle_id'])
            ->where('owner_id', $owner->id)->firstOrFail();
        $driver = \App\Models\Driver::where('id', $validated['driver_id'])
            ->where('owner_id', $owner->id)->firstOrFail();

        $trip = Trip::create([
            ...$validated,
            'business_id' => $vehicle->business_id ?? $owner->business_id,
            'available_seats' => $vehicle->total_seats,
            'status' => 'scheduled',
        ]);

        return response()->json(['data' => $trip->load(['route', 'vehicle', 'driver'])], 201);
    }

    public function start(Trip $trip): JsonResponse
    {
        $driver = auth()->user()->driver;
        if (!$driver || $trip->driver_id !== $driver->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $trip->update(['status' => 'started']);

        return response()->json(['data' => $trip]);
    }

    public function end(Trip $trip): JsonResponse
    {
        $driver = auth()->user()->driver;
        if (!$driver || $trip->driver_id !== $driver->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $trip->update(['status' => 'completed']);

        return response()->json(['data' => $trip]);
    }

    public function passengers(Trip $trip): JsonResponse
    {
        $driver = auth()->user()->driver;
        if (!$driver || $trip->driver_id !== $driver->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $passengers = Booking::with('customer')
            ->where('trip_id', $trip->id)
            ->whereIn('status', ['paid', 'completed'])
            ->get();

        return response()->json(['data' => $passengers]);
    }
}
