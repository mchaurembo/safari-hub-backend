<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $owner->status !== 'approved') {
            return response()->json(['message' => 'Transport owner not approved'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'license_number' => 'required|string|max:50',
            'experience_years' => 'nullable|integer|min:0',
        ]);

        $user = \App\Models\User::findOrFail($validated['user_id']);
        if ($user->role->name !== 'driver') {
            return response()->json(['message' => 'User must have driver role'], 422);
        }

        if (Driver::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'User is already a driver'], 422);
        }

        $driver = Driver::create([
            ...$validated,
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        return response()->json(['data' => $driver->load('user')], 201);
    }

    public function update(Request $request, Driver $driver): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $driver->owner_id !== $owner->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'license_number' => 'sometimes|string|max:50',
            'experience_years' => 'nullable|integer|min:0',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $driver->update($validated);

        return response()->json(['data' => $driver->load('user')]);
    }

    public function destroy(Request $request, Driver $driver): JsonResponse
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $driver->owner_id !== $owner->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $driver->delete();

        return response()->json(['message' => 'Driver removed'], 204);
    }

    public function myTrips(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;
        if (!$driver) {
            return response()->json(['message' => 'Not a driver'], 403);
        }

        $trips = \App\Models\Trip::with(['route', 'vehicle'])
            ->where('driver_id', $driver->id)
            ->orderByDesc('departure_time')
            ->get();

        return response()->json(['data' => $trips]);
    }
}
