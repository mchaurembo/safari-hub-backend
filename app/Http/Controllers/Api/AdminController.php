<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Garage;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TransportOwner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $users = User::with(['role', 'roles', 'transportOwner', 'driver', 'garages', 'technicians'])
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($users);
    }

    public function createUser(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/', function ($attribute, $value, $fail) {
                if ($value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $fail('Phone number must be 10-13 digits.');
                    }
                }
            }],
            'password' => ['required', Password::defaults()],
            'role' => 'sometimes|in:admin,owner,driver,customer,garage_owner,technician',
            'roles' => 'sometimes|array',
            'roles.*' => 'in:admin,owner,driver,customer,garage_owner,technician',
            'status' => 'sometimes|in:active,inactive,suspended',
            'company_name' => 'nullable|string|max:255',
            'license_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'garage_name' => 'nullable|string|max:255',
            'location' => 'nullable|string',
            'driver_license_number' => 'nullable|string|max:100',
            'driver_experience_years' => 'nullable|integer|min:0',
            'specialization' => 'nullable|string|max:255',
        ]);

        $roleNames = ! empty($validated['roles']) ? $validated['roles'] : [$validated['role'] ?? 'customer'];
        $roleNames = array_values(array_unique($roleNames));

        if (empty($roleNames)) {
            $roleNames = ['customer'];
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => $validated['status'] ?? 'active',
        ]);

        foreach ($roleNames as $name) {
            $user->enrollCapability($name);
            $this->ensureUserProfileForRole($user, $name, $validated, $request);
        }
        $user->refreshLegacyPrimaryRole();

        return response()->json([
            'message' => 'User created',
            'data' => $user->fresh(['role', 'roles', 'transportOwner', 'driver', 'garages']),
        ], 201);
    }

    private function ensureUserProfileForRole(User $user, string $roleName, array $validated, Request $request): void
    {
        if ($roleName === 'owner') {
            if (! $user->transportOwner) {
                TransportOwner::create([
                    'user_id' => $user->id,
                    'company_name' => $validated['company_name'] ?? $request->input('company_name', 'Company'),
                    'license_number' => $validated['license_number'] ?? $request->input('license_number', 'TEMP'),
                    'address' => $validated['address'] ?? $request->input('address'),
                    'status' => 'pending',
                ]);
            }
        }

        if ($roleName === 'driver') {
            $transportOwner = $user->transportOwner;
            if (! $transportOwner) {
                TransportOwner::create([
                    'user_id' => $user->id,
                    'company_name' => $validated['company_name'] ?? 'My Transport Company',
                    'license_number' => $validated['license_number'] ?? 'TEMP',
                    'address' => $validated['address'] ?? null,
                    'status' => 'pending',
                ]);
                $transportOwner = $user->transportOwner()->first();
            }
            if ($transportOwner && ! $user->driver) {
                Driver::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'owner_id' => $transportOwner->id,
                        'license_number' => $validated['driver_license_number'] ?? 'TEMP-DL',
                        'experience_years' => $validated['driver_experience_years'] ?? 0,
                        'status' => 'active',
                    ]
                );
            }
        }

        if ($roleName === 'garage_owner') {
            if (! $user->garages()->exists()) {
                Garage::create([
                    'owner_id' => $user->id,
                    'name' => $validated['garage_name'] ?? 'My Garage',
                    'location' => $validated['location'] ?? null,
                    'status' => 'active',
                ]);
            }
        }

        if ($roleName === 'technician') {
            if (! $user->garages()->exists()) {
                Garage::create([
                    'owner_id' => $user->id,
                    'name' => $validated['garage_name'] ?? 'My Garage',
                    'location' => $validated['location'] ?? null,
                    'status' => 'active',
                ]);
            }
            $garage = $user->garages()->orderByDesc('id')->first();
            if ($garage && ! Technician::where('user_id', $user->id)->where('garage_id', $garage->id)->exists()) {
                Technician::firstOrCreate(
                    ['user_id' => $user->id, 'garage_id' => $garage->id],
                    ['specialization' => $validated['specialization'] ?? 'General', 'status' => 'active']
                );
            }
        }
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$user->id,
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/', function ($attribute, $value, $fail) {
                if ($value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $fail('Phone number must be 10-13 digits.');
                    }
                }
            }],
            'role' => 'sometimes|in:admin,owner,driver,customer,garage_owner,technician',
            'roles' => 'sometimes|array',
            'roles.*' => 'in:admin,owner,driver,customer,garage_owner,technician',
            'status' => 'sometimes|in:active,inactive,suspended',
            'password' => ['sometimes', Password::defaults()],
            'company_name' => 'nullable|string|max:255',
            'license_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'garage_name' => 'nullable|string|max:255',
            'location' => 'nullable|string',
            'driver_license_number' => 'nullable|string|max:100',
            'driver_experience_years' => 'nullable|integer|min:0',
            'specialization' => 'nullable|string|max:255',
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (isset($validated['roles'])) {
            $roleNames = array_values(array_unique($validated['roles']));
            if (empty($roleNames)) {
                $roleNames = ['customer'];
            }
            $roleIds = [];
            foreach ($roleNames as $name) {
                $role = Role::firstOrCreate(['name' => $name]);
                $roleIds[$role->id] = [
                    'status' => 'active',
                    'started_at' => now(),
                ];
            }
            $user->roles()->sync($roleIds);
            $user->unsetRelation('roles');
            unset($validated['roles']);
            foreach ($roleNames as $roleName) {
                $this->ensureUserProfileForRole($user, $roleName, $validated, $request);
            }
            $user->refreshLegacyPrimaryRole();
        } elseif (isset($validated['role'])) {
            $role = Role::where('name', $validated['role'])->firstOrFail();
            $user->enrollCapability($role);
            $this->ensureUserProfileForRole($user, $validated['role'], $validated, $request);
            unset($validated['role']);
            // Do not write role_id from request — mirror from capabilities.
        }

        unset($validated['role_id']);
        $user->update(array_filter($validated, fn ($v) => $v !== null && $v !== ''));
        $user->refreshLegacyPrimaryRole();

        return response()->json([
            'message' => 'User updated',
            'data' => $user->fresh(['role', 'roles', 'transportOwner', 'driver', 'garages']),
        ]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate(['status' => 'required|in:active,inactive,suspended']);

        $user->update($validated);

        return response()->json(['message' => 'Status updated', 'data' => $user->fresh('role')]);
    }

    /**
     * Activate (add) a role for a user.
     */
    public function addUserRole(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'role' => 'required|in:admin,owner,driver,customer,garage_owner,technician',
        ]);

        $role = Role::firstOrCreate(['name' => $validated['role']]);

        if ($user->roles()->where('roles.id', $role->id)->exists()) {
            return response()->json(['message' => 'User already has this role'], 422);
        }

        $user->enrollCapability($role);
        $this->ensureUserProfileForRole($user, $validated['role'], $request->all(), $request);
        $user->refreshLegacyPrimaryRole();

        return response()->json([
            'message' => 'Role activated',
            'data' => $user->fresh(['role', 'roles', 'transportOwner', 'driver', 'garages']),
        ]);
    }

    /**
     * Deactivate (remove) a role from a user.
     */
    public function removeUserRole(Request $request, User $user, string $role): JsonResponse
    {
        $this->ensureAdmin($request);

        if (! in_array($role, ['admin', 'owner', 'driver', 'customer', 'garage_owner', 'technician'], true)) {
            return response()->json(['message' => 'Invalid role'], 422);
        }

        $roleModel = Role::where('name', $role)->first();

        if (! $roleModel) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $currentRoles = $user->roles()->pluck('roles.id')->toArray();

        if (! in_array($roleModel->id, $currentRoles, true)) {
            return response()->json(['message' => 'User does not have this role'], 422);
        }

        if (count($currentRoles) <= 1) {
            return response()->json(['message' => 'User must have at least one role'], 422);
        }

        $user->roles()->detach($roleModel->id);
        $user->unsetRelation('roles');
        $user->refreshLegacyPrimaryRole();

        return response()->json([
            'message' => 'Role deactivated',
            'data' => $user->fresh(['role', 'roles', 'transportOwner', 'driver', 'garages']),
        ]);
    }

    public function deleteUser(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot delete yourself'], 422);
        }

        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();

        return response()->json(['message' => 'User deactivated']);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $bookingsCount = Booking::whereIn('status', ['paid', 'completed'])
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $revenue = Payment::whereIn('status', ['completed', 'SUCCESS'])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        return response()->json([
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'bookings_count' => $bookingsCount,
                'total_revenue' => (float) $revenue,
            ],
        ]);
    }

    public function approveOwner(Request $request, TransportOwner $transportOwner): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate(['status' => 'required|in:approved,rejected,pending']);

        $transportOwner->update($validated);

        return response()->json(['message' => 'Owner updated', 'data' => $transportOwner->fresh('user')]);
    }

    public function approveOwnerByUser(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate(['status' => 'required|in:approved,rejected,pending']);

        // Create TransportOwner record if it doesn't exist
        $transportOwner = TransportOwner::firstOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => $user->name."'s Transport",
                'license_number' => 'PENDING-'.$user->id,
                'address' => null,
                'status' => 'pending',
            ]
        );

        $transportOwner->update(['status' => $validated['status']]);

        return response()->json(['message' => 'Owner status updated', 'data' => $transportOwner->fresh('user')]);
    }

    public function complaints(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $complaints = Complaint::with(['user', 'booking'])->orderByDesc('created_at')->get();

        return response()->json(['data' => $complaints]);
    }

    public function transportOwners(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $owners = TransportOwner::with('user')->orderByDesc('created_at')->get();

        return response()->json(['data' => $owners]);
    }

    public function garages(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $garages = Garage::with(['owner', 'technicians'])->orderByDesc('created_at')->get();

        return response()->json(['data' => $garages]);
    }

    public function resolveComplaint(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureAdmin($request);

        $complaint->update(['status' => 'resolved']);

        return response()->json(['message' => 'Complaint resolved', 'data' => $complaint]);
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->hasCapability('admin')) {
            abort(403, 'Admin access required');
        }
    }
}
