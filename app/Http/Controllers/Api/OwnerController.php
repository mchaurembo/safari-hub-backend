<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesBusinessContext;
use App\Http\Controllers\Concerns\ResolvesTransportFleet;
use App\Models\Booking;
use App\Models\CargoRequest;
use App\Models\Driver;
use App\Models\JobApplication;
use App\Models\EmploymentRelationship;
use App\Models\Payment;
use App\Models\TransportOwner;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AuthUserPresenter;
use App\Services\BusinessOperationService;
use App\Services\EmploymentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    use ResolvesTransportFleet;
    use ResolvesBusinessContext;

    public function __construct(
        private EmploymentService $employment,
        private BusinessOperationService $businessOps,
    ) {}

    /**
     * Search users the owner can hire: job seekers (applied / seeker profile)
     * or any active account by name/email/phone (add own driver).
     */
    public function availableDrivers(Request $request): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        $search = trim((string) $request->input('search', ''));
        $existingDriverUserIds = Driver::where('owner_id', $owner->id)->pluck('user_id');

        // Prefer seekers: users with a driver row not employed elsewhere, or pending applications.
        $seekerUserIds = Driver::query()
            ->where(function ($q) {
                $q->whereNull('owner_id')
                    ->orWhere('status', 'inactive');
            })
            ->pluck('user_id');

        $applicantUserIds = JobApplication::query()
            ->where('status', 'pending')
            ->whereHas('posting', fn ($q) => $q->where('transport_owner_id', $owner->id))
            ->with('driver')
            ->get()
            ->pluck('driver.user_id')
            ->filter()
            ->unique();

        $preferredIds = $seekerUserIds->merge($applicantUserIds)->unique()->values();

        $query = User::query()
            ->where('status', 'active')
            ->whereNotIn('id', $existingDriverUserIds)
            ->where(function ($q) use ($search, $preferredIds) {
                if ($search !== '') {
                    // Owner adding their own driver: search any user.
                    $q->where(function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                } else {
                    // No search: show job seekers / applicants first.
                    if ($preferredIds->isNotEmpty()) {
                        $q->whereIn('id', $preferredIds);
                    } else {
                        $q->whereRaw('0 = 1');
                    }
                }
            })
            ->select('id', 'name', 'email', 'phone')
            ->limit(20);

        $users = $query->get()->map(function ($u) use ($preferredIds, $applicantUserIds) {
            $u->is_job_seeker = $preferredIds->contains($u->id);
            $u->has_pending_application = $applicantUserIds->contains($u->id);

            return $u;
        });

        return response()->json(['data' => $users]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasCapability('transport_manager') && ! $user->hasCapability('owner')) {
            return response()->json(['message' => 'Transport managers cannot create fleet profiles.'], 403);
        }

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'license_number' => 'required|string|max:100',
            'address' => 'nullable|string',
        ]);

        $owner = TransportOwner::updateOrCreate(
            ['user_id' => $user->id],
            array_merge($validated, ['status' => 'pending'])
        );

        $this->businessOps->ensureBusinessForFleet($owner);

        return response()->json([
            'data' => $owner,
            'message' => 'Fleet profile saved. Awaiting admin approval.',
            'user' => AuthUserPresenter::present($user->fresh()),
        ]);
    }

    public function vehicles(Request $request): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        $query = Vehicle::with('drivers.user')->where('owner_id', $owner->id);
        if ($businessId = $this->activeBusinessId($request)) {
            $query->where('business_id', $businessId);
        }
        $vehicles = $query->orderByDesc('created_at')->get();

        return response()->json(['data' => $vehicles]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        $query = Driver::with('user')
            ->withCount('documents')
            ->where('owner_id', $owner->id);
        if ($businessId = $this->activeBusinessId($request)) {
            $query->where('business_id', $businessId);
        }
        $drivers = $query->orderByDesc('created_at')->get();

        return response()->json(['data' => $drivers]);
    }

    public function showDriver(Request $request, int $driver): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        $record = Driver::with([
            'user',
            'vehicles',
            'documents' => fn ($q) => $q->withTrashed()->latest(),
        ])
            ->where('owner_id', $owner->id)
            ->find($driver);

        if (! $record) {
            return response()->json(['message' => 'Driver not found'], 404);
        }

        return response()->json(['data' => $record]);
    }

    public function trips(Request $request): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        $trips = Trip::with(['route', 'vehicle', 'driver.user'])
            ->whereHas('vehicle', fn ($q) => $q->where('owner_id', $owner->id))
            ->orderByDesc('departure_time')
            ->get();

        return response()->json(['data' => $trips]);
    }

    // GET /owner/cargo-trips — all cargo requests for this owner's drivers
    public function cargoTrips(Request $request): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
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
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
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
                'driver_id' => $driverId,
                'driver_name' => $first->driver?->user?->name ?? 'Unknown',
                'trips' => $requests->count(),
                'total' => $requests->sum('quoted_price'),
                'last_trip' => $requests->max('updated_at'),
            ];
        })->values();

        // Monthly breakdown (last 6 months)
        $monthly = $completed->groupBy(fn ($r) => Carbon::parse($r->updated_at)->format('Y-m'))
            ->map(fn ($requests, $month) => [
                'month' => $month,
                'trips' => $requests->count(),
                'total' => $requests->sum('quoted_price'),
            ])
            ->sortKeys()
            ->values();

        return response()->json([
            'data' => [
                'total_earnings' => (float) $totalEarnings,
                'completed_trips' => $completed->count(),
                'by_driver' => $byDriver,
                'monthly' => $monthly,
            ],
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        try {
            $vehicleIds = Vehicle::where('owner_id', $owner->id)->pluck('id');
            $tripIds = Trip::whereIn('vehicle_id', $vehicleIds)->pluck('id');
            $bookingIds = Booking::whereIn('trip_id', $tripIds)
                ->whereIn('status', ['paid', 'completed'])
                ->pluck('id');

            $revenue = Payment::whereIn('booking_id', $bookingIds)
                ->whereIn('status', ['completed', 'SUCCESS'])
                ->sum('amount');
        } catch (\Exception $e) {
            $revenue = 0;
        }

        return response()->json(['data' => ['total_revenue' => (float) $revenue]]);
    }

    /** Fleet owned by this user — not a managed assignment. */
    private function requireOwnedFleet(Request $request): ?TransportOwner
    {
        return $request->user()?->transportOwner;
    }

    public function managers(Request $request): JsonResponse
    {
        $owner = $this->requireOwnedFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'Only the transport owner can view managers'], 403);
        }

        $managerUserIds = EmploymentRelationship::query()
            ->where('employer_type', EmploymentRelationship::EMPLOYER_TRANSPORT)
            ->where('employer_id', $owner->id)
            ->where('employment_type', EmploymentRelationship::TYPE_STAFF)
            ->where('position', 'manager')
            ->distinct()
            ->pluck('employee_user_id');

        $managers = User::query()
            ->whereIn('id', $managerUserIds)
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($owner) {
                $active = EmploymentRelationship::query()
                    ->where('employer_type', EmploymentRelationship::EMPLOYER_TRANSPORT)
                    ->where('employer_id', $owner->id)
                    ->where('employee_user_id', $user->id)
                    ->where('employment_type', EmploymentRelationship::TYPE_STAFF)
                    ->where('position', 'manager')
                    ->where('status', 'active')
                    ->exists();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => $active ? 'active' : 'inactive',
                ];
            })
            ->values();

        return response()->json(['data' => $managers]);
    }

    /**
     * Check whether a manager email already belongs to an account.
     */
    public function lookupManagerEmail(Request $request): JsonResponse
    {
        if (! $this->requireOwnedFleet($request)) {
            return response()->json(['message' => 'Only the transport owner can add managers'], 403);
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        return response()->json([
            'exists' => (bool) $user,
            'user' => $user?->only(['id', 'name', 'email', 'phone']),
        ]);
    }

    /**
     * Create a transport manager login (same pattern as garage Add Technician).
     */
    public function storeManager(Request $request): JsonResponse
    {
        $fleet = $this->requireOwnedFleet($request);
        if (! $fleet || $fleet->status !== 'approved') {
            return response()->json(['message' => 'Fleet not approved'], 403);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $existingUser = User::where('email', $email)->first();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'password' => 'nullable|string|min:6',
        ]);

        if ($existingUser) {
            unset($validated['password']);
            $validated['name'] = $existingUser->name;
            $validated['phone'] = $existingUser->phone;
        }

        [$user, $created] = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $fleet) {
            [$user, $created] = $this->employment->resolveOrCreateStaffUser($validated);

            if ((int) $user->id === (int) $fleet->user_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => ['You cannot add yourself as a manager.'],
                ]);
            }

            $this->employment->employTransportManager($fleet, $user);

            return [$user, $created];
        });

        return response()->json([
            'message' => $created
                ? 'Transport manager account created'
                : 'Transport manager workspace added to existing account',
            'linked_existing' => ! $created,
            'data' => $user->only(['id', 'name', 'email', 'phone']),
            'user' => AuthUserPresenter::present($user->fresh()),
        ], $created ? 201 : 200);
    }

    public function updateManager(Request $request, User $user): JsonResponse
    {
        $fleet = $this->requireOwnedFleet($request);
        if (! $fleet) {
            return response()->json(['message' => 'Only the transport owner can manage managers'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:active,inactive',
        ]);

        $assigned = EmploymentRelationship::query()
            ->where('employer_type', EmploymentRelationship::EMPLOYER_TRANSPORT)
            ->where('employer_id', $fleet->id)
            ->where('employee_user_id', $user->id)
            ->where('employment_type', EmploymentRelationship::TYPE_STAFF)
            ->where('position', 'manager')
            ->exists();

        if (! $assigned) {
            return response()->json(['message' => 'Manager not found for this fleet'], 404);
        }

        if ($validated['status'] === 'active') {
            $this->employment->employTransportManager($fleet, $user);
        } else {
            $this->employment->releaseTransportManager($fleet, $user);
        }

        $active = $validated['status'] === 'active';

        return response()->json([
            'message' => $active ? 'Transport manager activated' : 'Transport manager deactivated',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $active ? 'active' : 'inactive',
            ],
            'user' => AuthUserPresenter::present($user->fresh()),
        ]);
    }

    public function destroyManager(Request $request, User $user): JsonResponse
    {
        $fleet = $this->requireOwnedFleet($request);
        if (! $fleet) {
            return response()->json(['message' => 'Only the transport owner can remove managers'], 403);
        }

        $this->employment->releaseTransportManager($fleet, $user);

        return response()->json([
            'message' => 'Transport manager removed',
            'user' => AuthUserPresenter::present($user->fresh()),
        ]);
    }
}
