<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $owner->status !== 'approved') {
            return response()->json(['message' => 'Transport owner not approved'], 403);
        }

        $validated = $request->validate([
            'vehicle_number' => 'required|string|max:50',
            'vehicle_type' => 'required|string|max:50',
            'total_seats' => 'required|integer|min:1',
            'model' => 'nullable|string|max:100',
        ]);

        $vehicle = Vehicle::create([
            ...$validated,
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        return response()->json(['data' => $vehicle], 201);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $vehicle->owner_id !== $owner->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'vehicle_number' => 'sometimes|string|max:50',
            'vehicle_type' => 'sometimes|string|max:50',
            'total_seats' => 'sometimes|integer|min:1',
            'model' => 'nullable|string|max:100',
            'status' => 'sometimes|in:active,inactive,maintenance',
        ]);

        $vehicle->update($validated);

        return response()->json(['data' => $vehicle]);
    }

    public function destroy(Request $request, Vehicle $vehicle): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $vehicle->owner_id !== $owner->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $vehicle->delete();

        return response()->json(['message' => 'Vehicle deleted'], 204);
    }

    public function assignDriver(Request $request, Vehicle $vehicle): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $vehicle->owner_id !== $owner->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate(['driver_id' => 'required|exists:drivers,id']);

        $driver = Driver::findOrFail($validated['driver_id']);
        if ($driver->owner_id !== $owner->id) {
            return response()->json(['message' => 'Driver does not belong to your fleet'], 403);
        }

        $vehicle->drivers()->syncWithoutDetaching([$driver->id]);

        return response()->json([
            'message' => 'Driver assigned to vehicle',
            'data' => $vehicle->load('drivers.user'),
        ]);
    }

    public function unassignDriver(Request $request, Vehicle $vehicle, Driver $driver): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $vehicle->owner_id !== $owner->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $vehicle->drivers()->detach($driver->id);

        return response()->json([
            'message' => 'Driver removed from vehicle',
            'data' => $vehicle->load('drivers.user'),
        ]);
    }
}
