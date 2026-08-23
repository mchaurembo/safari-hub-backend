<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesTransportFleet;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Services\BusinessOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    use ResolvesTransportFleet;

    public function __construct(
        private AuditLogger $audit,
        private BusinessOperationService $businessOps,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Vehicle::class);

        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
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
            'business_id' => $this->businessOps->businessIdForNewVehicle($owner->id),
            'status' => 'active',
        ]);

        $this->audit->log('vehicle.created', $vehicle, null, $vehicle->toArray());

        return response()->json(['data' => $vehicle], 201);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

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
        $this->authorize('delete', $vehicle);

        $vehicle->delete();

        return response()->json(['message' => 'Vehicle deleted'], 204);
    }

    public function assignDriver(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('assignDriver', $vehicle);

        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        $validated = $request->validate(['driver_id' => 'required|exists:drivers,id']);

        $driver = Driver::findOrFail($validated['driver_id']);
        if ($driver->owner_id !== $owner->id) {
            return response()->json(['message' => 'Driver does not belong to your fleet'], 403);
        }

        $vehicle->drivers()->syncWithoutDetaching([$driver->id]);

        $this->audit->log('vehicle.driver_assigned', $vehicle, null, [
            'driver_id' => $driver->id,
        ]);

        return response()->json([
            'message' => 'Driver assigned to vehicle',
            'data' => $vehicle->load('drivers.user'),
        ]);
    }

    public function unassignDriver(Request $request, Vehicle $vehicle, Driver $driver): JsonResponse
    {
        $this->authorize('assignDriver', $vehicle);

        $vehicle->drivers()->detach($driver->id);

        $this->audit->log('vehicle.driver_unassigned', $vehicle, null, [
            'driver_id' => $driver->id,
        ]);

        return response()->json([
            'message' => 'Driver removed from vehicle',
            'data' => $vehicle->load('drivers.user'),
        ]);
    }
}
