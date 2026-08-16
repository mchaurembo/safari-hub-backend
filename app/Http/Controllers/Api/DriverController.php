<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Services\EmploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DriverController extends Controller
{
    public function __construct(private EmploymentService $employment) {}

    /**
     * Owner adds / hires a driver into their fleet.
     * Grants driver capability — seekers cannot self-enroll as driver.
     */
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

        try {
            $driver = $this->employment->employDriver($owner, $user, [
                'license_number' => $validated['license_number'],
                'experience_years' => $validated['experience_years'] ?? 0,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $driver], 201);
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

        $driver->update(['status' => 'inactive', 'owner_id' => null]);

        \App\Models\EmploymentRelationship::query()
            ->where('employer_type', \App\Models\EmploymentRelationship::EMPLOYER_TRANSPORT)
            ->where('employer_id', $owner->id)
            ->where('employee_user_id', $driver->user_id)
            ->where('employment_type', \App\Models\EmploymentRelationship::TYPE_DRIVER)
            ->where('status', 'active')
            ->update([
                'status' => 'ended',
                'end_date' => now()->toDateString(),
            ]);

        // Revoke driver capability if no longer employed by any fleet.
        if (! Driver::where('user_id', $driver->user_id)->whereNotNull('owner_id')->where('status', 'active')->exists()) {
            $role = \App\Models\Role::where('name', 'driver')->first();
            if ($role) {
                $driver->user->roles()->updateExistingPivot($role->id, [
                    'status' => 'revoked',
                    'ended_at' => now(),
                ]);
                $driver->user->unsetRelation('roles');
                $driver->user->refreshLegacyPrimaryRole();
            }
        }

        return response()->json(['message' => 'Driver removed from fleet'], 200);
    }

    public function myTrips(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;
        if (!$driver || ! $driver->owner_id) {
            return response()->json(['message' => 'Not an employed driver'], 403);
        }

        $trips = \App\Models\Trip::with(['route', 'vehicle'])
            ->where('driver_id', $driver->id)
            ->orderByDesc('departure_time')
            ->get();

        return response()->json(['data' => $trips]);
    }
}
