<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NameHelper;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Garage;
use App\Models\GarageBooking;
use App\Models\GarageMember;
use App\Models\GarageService;
use App\Models\Role;
use App\Models\Technician;
use App\Models\User;
use App\Services\BusinessOperationService;
use App\Services\EmploymentService;
use App\Services\GarageWorkflowService;
use App\Services\LegacyBusinessAccessService;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class GarageController extends Controller
{
    public function __construct(
        private NotificationService $notify,
        private EmploymentService $employment,
        private GarageWorkflowService $workflow,
        private BusinessOperationService $businessOps,
        private LegacyBusinessAccessService $legacyAccess,
    ) {}

    public function ping(): JsonResponse
    {
        return response()->json([
            'module' => 'garage',
            'status' => 'ok',
            'phase' => 1,
        ]);
    }

    /** List active garages for technicians joining (directory). */
    public function directory(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $query = Garage::query()
            ->where('status', 'active')
            ->select('id', 'name', 'location', 'status')
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'garages' => $query->limit(50)->get(),
        ]);
    }

    /** Create a garage business for the authenticated garage_owner capability. */
    public function createGarage(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('create', Garage::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:1000',
        ]);

        $garage = Garage::create([
            'owner_id' => $user->id,
            'name' => $validated['name'],
            'location' => $validated['location'] ?? null,
            'status' => 'active',
        ]);

        $this->employment->ensureGarageOwnerMembership($garage);
        $this->businessOps->ensureBusinessForGarage($garage);

        return response()->json([
            'garage' => $garage,
            'message' => 'Garage created',
            'user' => \App\Support\AuthUserPresenter::present($user->fresh()),
        ], 201);
    }

    /** Join an existing garage as technician (does not create a garage). */
    public function joinAsTechnician(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'garage_id' => 'required|exists:garages,id',
            'specialization' => 'nullable|string|max:255',
        ]);

        $garage = Garage::findOrFail($validated['garage_id']);
        if ($garage->status !== 'active') {
            return response()->json(['message' => 'Garage is not accepting technicians'], 422);
        }

        $tech = $this->employment->employTechnician(
            $garage,
            $user,
            $validated['specialization'] ?? 'General'
        );

        return response()->json([
            'technician' => $tech->load('garage:id,name,location'),
            'message' => 'Joined garage as technician',
            'user' => \App\Support\AuthUserPresenter::present($user->fresh()),
        ], 201);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $garage = $this->resolveGarage($request);
        if (! $garage) {
            $message = 'No garage profile found. Create your garage to continue.';
            if ($user->hasCapability('garage_manager') && ! $user->hasCapability('garage_owner')) {
                $message = 'No garage assignment yet. A garage owner must add you as manager first.';
            } elseif ($user->hasCapability('technician') && ! $user->hasCapability('garage_owner')) {
                $message = 'No garage linked yet. Join a garage as a technician first.';
            } elseif ($user->hasCapability('technician')) {
                $message = 'No garage profile found. Create your garage or join one as a technician.';
            }

            return response()->json(['message' => $message], 404);
        }

        $canManageGarage = $user->ownsGarage($garage);
        $isOwner = (int) $garage->owner_id === (int) $user->id;
        $tech = Technician::where('user_id', $user->id)->where('garage_id', $garage->id)->first();
        $roleContext = $isOwner ? 'owner' : ($canManageGarage ? 'manager' : 'technician');

        $today = Carbon::today();
        $bookings = GarageBooking::where('garage_id', $garage->id);
        if (! $canManageGarage && $tech) {
            $bookings->where('technician_id', $tech->id);
        }

        $stats = [
            'total_bookings' => (clone $bookings)->count(),
            'today_bookings' => (clone $bookings)->whereDate('scheduled_at', $today)->count(),
            'pending' => (clone $bookings)->where('status', 'pending')->count(),
            'confirmed' => (clone $bookings)->where('status', 'confirmed')->count(),
            'assigned' => (clone $bookings)->where('status', 'assigned')->count(),
            'queued' => (clone $bookings)->whereIn('status', ['pending', 'confirmed', 'assigned'])->count(),
            'in_progress' => (clone $bookings)->where('status', 'in_progress')->count(),
            'completed' => (clone $bookings)->where('status', 'completed')->count(),
            'cancelled' => (clone $bookings)->where('status', 'cancelled')->count(),
            'technicians' => $canManageGarage ? $garage->technicians()->where('status', 'active')->count() : null,
            'services' => $canManageGarage ? $garage->services()->where('status', 'active')->count() : null,
            'revenue' => $canManageGarage
                ? (float) GarageBooking::where('garage_id', $garage->id)->where('status', 'completed')->sum('amount')
                : null,
        ];

        $recentQuery = GarageBooking::with(['customer:id,name,email,phone', 'service:id,name,price', 'technician.user:id,name'])
            ->where('garage_id', $garage->id);
        if (! $canManageGarage && $tech) {
            $recentQuery->where('technician_id', $tech->id);
        }

        return response()->json([
            'role_context' => $roleContext,
            'technician_id' => $tech?->id,
            'garage' => $garage,
            'stats' => $stats,
            'recent_bookings' => $recentQuery->latest()->limit(8)->get(),
        ]);
    }

    public function showGarage(Request $request): JsonResponse
    {
        $garage = $this->resolveGarage($request);
        if (! $garage) {
            return response()->json(['message' => 'No garage profile found.'], 404);
        }

        return response()->json([
            'garage' => $garage->loadCount(['services', 'technicians', 'bookings']),
        ]);
    }

    public function updateGarage(Request $request): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);
        $this->authorize('update', $garage);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'location' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:active,inactive',
        ]);
        $garage->update($validated);

        return response()->json(['garage' => $garage->fresh()]);
    }

    public function services(Request $request): JsonResponse
    {
        $garage = $this->resolveGarage($request);
        if (! $garage) {
            return response()->json(['message' => 'No garage profile found.'], 404);
        }

        $query = $garage->services()->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['services' => $query->get()]);
    }

    public function storeService(Request $request): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);
        $this->authorize('manageServices', $garage);
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'service_catalog_item_id' => 'nullable|exists:service_catalog_items,id',
            'description' => 'nullable|string|max:2000',
            'price' => 'nullable|numeric|min:0',
            'type' => 'nullable|in:fixed,estimate',
            'duration_minutes' => 'nullable|integer|min:15|max:1440',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if (! empty($validated['service_catalog_item_id'])) {
            $catalogItem = \App\Models\ServiceCatalogItem::query()
                ->where('is_active', true)
                ->findOrFail($validated['service_catalog_item_id']);
            $validated['name'] = $validated['name'] ?: $catalogItem->name;
            $validated['description'] = $validated['description'] ?? $catalogItem->description;
            $validated['type'] = $validated['type'] ?? $catalogItem->default_pricing_type;
            if (empty($validated['duration_minutes']) && $catalogItem->default_duration_minutes) {
                $validated['duration_minutes'] = $catalogItem->default_duration_minutes;
            }
        }

        if (empty($validated['name'])) {
            return response()->json(['message' => 'Service name is required.'], 422);
        }

        $validated['type'] = $validated['type'] ?? 'fixed';

        $service = $garage->services()->create([
            ...$validated,
            'business_id' => $this->businessOps->businessIdForGarage($garage->id),
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['service' => $service], 201);
    }

    public function updateService(Request $request, GarageService $service): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);
        if ($service->garage_id !== $garage->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'nullable|numeric|min:0',
            'type' => 'sometimes|in:fixed,estimate',
            'duration_minutes' => 'nullable|integer|min:15|max:1440',
            'status' => 'sometimes|in:active,inactive',
        ]);
        $service->update($validated);

        return response()->json(['service' => $service->fresh()]);
    }

    public function destroyService(Request $request, GarageService $service): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);
        if ($service->garage_id !== $garage->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($service->bookings()->exists()) {
            $service->update(['status' => 'inactive']);

            return response()->json(['message' => 'Service deactivated (has bookings)', 'service' => $service->fresh()]);
        }
        $service->delete();

        return response()->json(['message' => 'Service deleted']);
    }

    public function technicians(Request $request): JsonResponse
    {
        $garage = $this->resolveGarage($request);
        if (! $garage) {
            return response()->json(['message' => 'No garage profile found.'], 404);
        }

        $techs = $garage->technicians()
            ->with('user:id,name,email,phone')
            ->withCount([
                'bookings as open_jobs' => fn ($q) => $q->whereIn('status', ['assigned', 'in_progress', 'confirmed']),
            ])
            ->latest()
            ->get();

        return response()->json(['technicians' => $techs]);
    }

    public function storeTechnician(Request $request): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'password' => 'nullable|string|min:6',
            'specialization' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive,busy',
        ]);

        $role = Role::firstOrCreate(['name' => 'technician']);

        $user = DB::transaction(function () use ($validated, $garage, $role) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'] ?? 'password',
                'status' => 'active',
            ]);
            $user->enrollCapability($role);

            $this->employment->employTechnician(
                $garage,
                $user,
                $validated['specialization'] ?? 'General'
            );

            if (isset($validated['status']) && $validated['status'] !== 'active') {
                Technician::where('user_id', $user->id)->where('garage_id', $garage->id)
                    ->update(['status' => $validated['status']]);
            }

            return $user;
        });

        $tech = Technician::with('user:id,name,email,phone')
            ->where('user_id', $user->id)
            ->where('garage_id', $garage->id)
            ->first();

        return response()->json(['technician' => $tech], 201);
    }

    public function updateTechnician(Request $request, Technician $technician): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);
        if ($technician->garage_id !== $garage->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'specialization' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive,busy',
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
        ]);

        if (isset($validated['specialization']) || isset($validated['status'])) {
            $technician->update(array_intersect_key($validated, array_flip(['specialization', 'status'])));
        }
        if (isset($validated['name']) || array_key_exists('phone', $validated)) {
            $technician->user->update(array_filter([
                'name' => $validated['name'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if (isset($validated['status'])) {
            if ($validated['status'] === 'active') {
                if (! $technician->user->hasCapability('technician')) {
                    $technician->user->enrollCapability('technician');
                }
                $this->employment->ensureGarageMembership(
                    $garage,
                    $technician->user,
                    \App\Models\GarageMember::TYPE_TECHNICIAN
                );
            } elseif ($validated['status'] === 'inactive') {
                $this->employment->endGarageMembership(
                    $garage,
                    $technician->user,
                    \App\Models\GarageMember::TYPE_TECHNICIAN
                );
                if (! $technician->user->fresh()->hasActiveTechnicianWorkspace()) {
                    $technician->user->unenrollCapability('technician');
                }
            }
        }

        return response()->json(['technician' => $technician->fresh()->load('user:id,name,email,phone')]);
    }

    /** Customers derived from this garage's bookings (no separate CRM list). */
    public function customers(Request $request): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);

        $query = User::query()
            ->whereIn('id', GarageBooking::query()
                ->where('garage_id', $garage->id)
                ->select('customer_id')
                ->distinct())
            ->withCount([
                'garageBookings as bookings_count' => fn ($q) => $q->where('garage_id', $garage->id),
                'garageBookings as completed_count' => fn ($q) => $q->where('garage_id', $garage->id)->where('status', 'completed'),
            ])
            ->withMax([
                'garageBookings as last_booking_at' => fn ($q) => $q->where('garage_id', $garage->id),
            ], 'scheduled_at');

        if ($request->filled('q')) {
            $q = '%'.$request->q.'%';
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', $q)
                    ->orWhere('email', 'like', $q)
                    ->orWhere('phone', 'like', $q);
            });
        }

        $users = $query->orderBy('name')->get(['id', 'name', 'email', 'phone', 'whatsapp_number']);

        $latestByCustomer = GarageBooking::query()
            ->where('garage_id', $garage->id)
            ->whereIn('customer_id', $users->pluck('id'))
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->get(['customer_id', 'vehicle_reg', 'status', 'scheduled_at'])
            ->unique('customer_id')
            ->keyBy('customer_id');

        $customers = $users->map(function (User $user) use ($latestByCustomer) {
            $latest = $latestByCustomer->get($user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'whatsapp_number' => $user->whatsapp_number,
                'bookings_count' => (int) $user->bookings_count,
                'completed_count' => (int) $user->completed_count,
                'last_booking_at' => $user->last_booking_at,
                'last_vehicle_reg' => $latest?->vehicle_reg,
                'last_status' => $latest?->status,
            ];
        });

        return response()->json(['customers' => $customers]);
    }

    /** Update contact details for a customer who has booked at this garage. */
    public function updateCustomer(Request $request, User $customer): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);

        $belongs = GarageBooking::where('garage_id', $garage->id)
            ->where('customer_id', $customer->id)
            ->exists();
        if (! $belongs) {
            return response()->json(['message' => 'Customer not found for this garage.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($customer->id),
            ],
            'phone' => 'nullable|string|max:30',
            'whatsapp_number' => 'nullable|string|max:30',
        ]);

        if (array_key_exists('email', $validated)) {
            $validated['email'] = strtolower($validated['email']);
        }
        if (array_key_exists('name', $validated)) {
            $validated['name'] = NameHelper::personName($validated['name']);
        }

        $customer->update($validated);

        return response()->json([
            'customer' => $customer->fresh()->only(['id', 'name', 'email', 'phone', 'whatsapp_number']),
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $garage = $this->resolveGarage($request);
        if (! $garage) {
            return response()->json(['message' => 'No garage profile found.'], 404);
        }

        $query = GarageBooking::with([
            'customer:id,name,email,phone',
            'service:id,name,price,type',
            'technician.user:id,name',
            'workOrder:id,garage_booking_id,status,total_amount,started_at,completed_at',
        ])->where('garage_id', $garage->id);

        // Technicians only see their assigned jobs
        if ($this->isTechnicianOnly($request->user())) {
            $tech = Technician::where('user_id', $request->user()->id)->where('garage_id', $garage->id)->first();
            $query->where('technician_id', $tech?->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->whereDate('scheduled_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('scheduled_at', '<=', $request->to);
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));
        $bookings = $query->orderBy('id')->paginate($perPage);

        return response()->json($bookings);
    }

    public function storeBooking(Request $request): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request);
        $validated = $request->validate([
            'customer_name' => 'required_without:customer_id|string|max:255',
            'customer_email' => 'required_without:customer_id|email|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'customer_id' => [
                'nullable',
                'exists:users,id',
                // Prefer reusing customers already known via this garage's bookings
                Rule::exists('garage_bookings', 'customer_id')->where('garage_id', $garage->id),
            ],
            'service_id' => [
                'required',
                Rule::exists('garage_services', 'id')->where('garage_id', $garage->id),
            ],
            'technician_id' => [
                'nullable',
                Rule::exists('technicians', 'id')->where('garage_id', $garage->id),
            ],
            'vehicle_reg' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'scheduled_at' => 'nullable|date',
            'status' => 'sometimes|in:'.implode(',', GarageBooking::STATUSES),
        ]);

        $service = GarageService::findOrFail($validated['service_id']);

        try {
            $customerId = $validated['customer_id']
                ?? $this->resolveCustomerForBooking(
                    name: NameHelper::personName($validated['customer_name'] ?? 'Customer'),
                    email: $validated['customer_email'] ?? null,
                    phone: $validated['customer_phone'] ?? null,
                )->id;
        } catch (QueryException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'already registered') || str_contains($msg, 'Duplicate')) {
                return response()->json([
                    'message' => 'This phone or email is already registered to another account. Select the customer from your list, or use a different phone/email.',
                ], 422);
            }
            Log::error('Garage booking customer resolve failed', ['error' => $msg]);

            return response()->json(['message' => 'Could not save customer details.'], 500);
        }

        $status = $validated['status'] ?? 'pending';
        if (! empty($validated['technician_id']) && $status === 'pending') {
            $status = 'assigned';
        }

        try {
            $businessId = $this->businessOps->businessIdForNewGarageBooking($garage->id);
            $booking = GarageBooking::create([
                'business_id' => $businessId,
                'customer_id' => $customerId,
                'garage_id' => $garage->id,
                'service_id' => $service->id,
                'technician_id' => $validated['technician_id'] ?? null,
                'vehicle_reg' => $validated['vehicle_reg'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'amount' => $service->price,
                'status' => $status,
                'scheduled_at' => $validated['scheduled_at'] ?? now()->addDay(),
            ]);
        } catch (QueryException $e) {
            Log::error('Garage booking create failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not create booking.'], 500);
        }

        $workOrder = $this->workflow->syncFromBooking($booking);

        return response()->json([
            'booking' => $booking->load(['customer:id,name,email,phone', 'service', 'technician.user:id,name', 'workOrder']),
            'work_order' => $workOrder,
        ], 201);
    }

    /**
     * Find or create a customer user without violating global phone uniqueness triggers.
     */
    private function resolveCustomerForBooking(string $name, ?string $email, ?string $phone): User
    {
        $name = NameHelper::personName($name) ?: 'Customer';
        $email = $email ? strtolower(trim($email)) : null;
        $normalizedPhone = PhoneHelper::normalize($phone);

        $byEmail = $email ? User::where('email', $email)->first() : null;
        $byPhone = null;
        if ($normalizedPhone) {
            $byPhone = User::where('phone', $normalizedPhone)
                ->orWhere('whatsapp_number', $normalizedPhone)
                ->first();
        }

        // Prefer phone owner so WhatsApp/SMS can deliver to the number entered on the booking.
        $customer = $byPhone ?: $byEmail;

        $customerRole = Role::firstOrCreate(['name' => 'customer']);

        if ($customer) {
            $updates = [];
            if ($name && $customer->name !== $name) {
                $updates['name'] = $name;
            }
            // Attach email only when free (do not steal another account's email)
            if ($email && ! $customer->email) {
                $emailTaken = User::where('email', $email)->where('id', '!=', $customer->id)->exists();
                if (! $emailTaken) {
                    $updates['email'] = $email;
                }
            } elseif ($email && $customer->email !== $email && ! $byPhone) {
                // Email-matched user: try to set phone only if free
            }

            if ($normalizedPhone) {
                $phoneTaken = User::where('id', '!=', $customer->id)
                    ->where(function ($q) use ($normalizedPhone) {
                        $q->where('phone', $normalizedPhone)
                            ->orWhere('whatsapp_number', $normalizedPhone);
                    })
                    ->exists();
                if (! $phoneTaken) {
                    if (! $customer->phone) {
                        $updates['phone'] = $normalizedPhone;
                    }
                    if (! $customer->whatsapp_number) {
                        $updates['whatsapp_number'] = $normalizedPhone;
                    }
                }
            }
            if ($updates !== []) {
                $customer->update($updates);
            }
            $customer->roles()->syncWithoutDetaching([$customerRole->id]);

            return $customer->fresh();
        }

        // New user: omit phone if another account already owns it
        $attrs = [
            'name' => $name,
            'password' => Hash::make('password'),
            'status' => 'active',
        ];
        if ($email) {
            $attrs['email'] = $email;
        } else {
            $attrs['email'] = 'garage-customer-'.uniqid('', true).'@safarihub.local';
        }

        if ($normalizedPhone) {
            $phoneTaken = User::where('phone', $normalizedPhone)
                ->orWhere('whatsapp_number', $normalizedPhone)
                ->exists();
            if (! $phoneTaken) {
                $attrs['phone'] = $normalizedPhone;
                $attrs['whatsapp_number'] = $normalizedPhone;
            }
        }

        $customer = User::create($attrs);
        $customer->enrollCapability($customerRole);
        $customer->refreshLegacyPrimaryRole();

        return $customer;
    }

    public function updateBooking(Request $request, GarageBooking $booking): JsonResponse
    {
        $this->authorize('manage', $booking);

        $garage = $booking->garage;
        $user = $request->user();
        $canManageGarage = $garage && $user->ownsGarage($garage);

        $rules = [
            'status' => 'sometimes|in:'.implode(',', GarageBooking::STATUSES),
            'notes' => 'nullable|string|max:2000',
            'vehicle_reg' => 'nullable|string|max:50',
            'scheduled_at' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0',
        ];
        if ($canManageGarage) {
            $rules['technician_id'] = [
                'nullable',
                Rule::exists('technicians', 'id')->where('garage_id', $garage->id),
            ];
            $rules['service_id'] = [
                'sometimes',
                Rule::exists('garage_services', 'id')->where('garage_id', $garage->id),
            ];
        }

        $validated = $request->validate($rules);

        // Technicians: only operational status changes on their assigned jobs
        if (! $canManageGarage) {
            unset($validated['technician_id'], $validated['service_id'], $validated['amount'], $validated['scheduled_at']);
            if (isset($validated['status'])) {
                $allowed = ['in_progress', 'completed'];
                if (! in_array($validated['status'], $allowed, true)) {
                    return response()->json([
                        'message' => 'Technicians can only start or complete assigned jobs.',
                    ], 422);
                }
                $from = $booking->status;
                if ($validated['status'] === 'in_progress' && ! in_array($from, ['assigned', 'confirmed'], true)) {
                    return response()->json(['message' => 'Job cannot be started from status: '.$from], 422);
                }
                if ($validated['status'] === 'completed' && $from !== 'in_progress') {
                    return response()->json(['message' => 'Complete only after job is in progress.'], 422);
                }
            }
        }

        if ($canManageGarage && array_key_exists('technician_id', $validated) && $validated['technician_id'] && ($validated['status'] ?? $booking->status) === 'pending') {
            $validated['status'] = $validated['status'] ?? 'assigned';
        }

        $previousStatus = $booking->status;
        $booking->update($validated);
        $booking = $booking->fresh()->load([
            'customer:id,name,email,phone,whatsapp_number',
            'service',
            'technician.user:id,name',
            'garage',
            'workOrder.items',
        ]);

        $workOrder = $this->workflow->syncFromBooking($booking, $previousStatus);

        $newStatus = $booking->status;
        if ($newStatus !== $previousStatus && in_array($newStatus, ['in_progress', 'completed'], true)) {
            $this->notifyCustomerOfServiceStatus($booking, $newStatus);
        }

        return response()->json([
            'booking' => $booking->fresh(['workOrder.items', 'customer:id,name,email,phone,whatsapp_number', 'service', 'technician.user:id,name', 'garage']),
            'work_order' => $workOrder,
        ]);
    }

    private function notifyCustomerOfServiceStatus(GarageBooking $booking, string $status): void
    {
        $customer = $booking->customer;
        if (! $customer) {
            return;
        }

        $garageName = $booking->garage?->name ?? 'Garage';
        $serviceName = $booking->service?->name ?? 'Service';
        $technicianName = $booking->technician?->user?->name ?? 'Technician';

        try {
            if ($status === 'in_progress') {
                $this->notify->customerGarageServiceStarted(
                    customerName: $customer->name,
                    customerEmail: $customer->email,
                    customerPhone: $customer->phone,
                    garageName: $garageName,
                    serviceName: $serviceName,
                    technicianName: $technicianName,
                    vehicleReg: $booking->vehicle_reg,
                    customerWhatsapp: $customer->whatsapp_number ?: $customer->phone,
                );
            } elseif ($status === 'completed') {
                $this->notify->customerGarageServiceCompleted(
                    customerName: $customer->name,
                    customerEmail: $customer->email,
                    customerPhone: $customer->phone,
                    garageName: $garageName,
                    serviceName: $serviceName,
                    technicianName: $technicianName,
                    vehicleReg: $booking->vehicle_reg,
                    amount: $booking->amount !== null ? (float) $booking->amount : null,
                    customerWhatsapp: $customer->whatsapp_number ?: $customer->phone,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Garage customer notification failed', [
                'booking_id' => $booking->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveGarage(Request $request): ?Garage
    {
        return $this->legacyAccess->garageForRequest($request->user(), $request);
    }

    private function requireOwnerGarage(Request $request): Garage
    {
        return $this->legacyAccess->requireOwnerGarage($request->user(), $request);
    }

    private function isTechnicianOnly(User $user): bool
    {
        return $user->hasCapability('technician')
            && ! $user->hasCapability('garage_owner')
            && ! $user->hasCapability('garage_manager')
            && ! Garage::where('owner_id', $user->id)->exists();
    }

    /** Only the garage business owner (not an operational manager) may hire/release managers. */
    private function requireGarageBusinessOwner(User $user): Garage
    {
        $garage = Garage::where('owner_id', $user->id)->first();
        abort_unless($garage, 403, 'Only the garage owner can manage garage managers.');

        return $garage;
    }

    public function managers(Request $request): JsonResponse
    {
        $garage = $this->requireGarageBusinessOwner($request->user());

        $managers = $garage->members()
            ->where('membership_type', GarageMember::TYPE_MANAGER)
            ->with('user:id,name,email,phone')
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->map(function (GarageMember $member) {
                $user = $member->user;
                if (! $user) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => ($member->status === 'active' && $member->left_at === null) ? 'active' : 'inactive',
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $managers]);
    }

    /**
     * Check whether a manager email already belongs to an account.
     */
    public function lookupManagerEmail(Request $request): JsonResponse
    {
        $this->requireGarageBusinessOwner($request->user());

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
     * Create a garage manager login (same pattern as Add Technician).
     */
    public function storeManager(Request $request): JsonResponse
    {
        $garage = $this->requireGarageBusinessOwner($request->user());

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

        [$user, $created] = DB::transaction(function () use ($validated, $garage) {
            [$user, $created] = $this->employment->resolveOrCreateStaffUser($validated);

            if ((int) $user->id === (int) $garage->owner_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => ['You cannot add yourself as a manager.'],
                ]);
            }

            $this->employment->employGarageManager($garage, $user);

            return [$user, $created];
        });

        return response()->json([
            'message' => $created
                ? 'Garage manager account created'
                : 'Garage manager workspace added to existing account',
            'linked_existing' => ! $created,
            'data' => $user->only(['id', 'name', 'email', 'phone']),
            'user' => \App\Support\AuthUserPresenter::present($user->fresh()),
        ], $created ? 201 : 200);
    }

    public function updateManager(Request $request, User $user): JsonResponse
    {
        $garage = $this->requireGarageBusinessOwner($request->user());

        $validated = $request->validate([
            'status' => 'required|in:active,inactive',
        ]);

        $assigned = $garage->members()
            ->where('membership_type', GarageMember::TYPE_MANAGER)
            ->where('user_id', $user->id)
            ->exists();

        if (! $assigned) {
            return response()->json(['message' => 'Manager not found for this garage'], 404);
        }

        if ($validated['status'] === 'active') {
            $this->employment->employGarageManager($garage, $user);
        } else {
            $this->employment->releaseGarageManager($garage, $user);
        }

        $active = $validated['status'] === 'active';

        return response()->json([
            'message' => $active ? 'Garage manager activated' : 'Garage manager deactivated',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $active ? 'active' : 'inactive',
            ],
            'user' => \App\Support\AuthUserPresenter::present($user->fresh()),
        ]);
    }

    public function destroyManager(Request $request, User $user): JsonResponse
    {
        $garage = $this->requireGarageBusinessOwner($request->user());
        $this->employment->releaseGarageManager($garage, $user);

        return response()->json([
            'message' => 'Garage manager removed',
            'user' => \App\Support\AuthUserPresenter::present($user->fresh()),
        ]);
    }
}
