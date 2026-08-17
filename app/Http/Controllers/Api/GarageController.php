<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NameHelper;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Garage;
use App\Models\GarageBooking;
use App\Models\GarageService;
use App\Models\Role;
use App\Models\Technician;
use App\Models\User;
use App\Services\EmploymentService;
use App\Services\GarageWorkflowService;
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
        $user->enrollCapability('garage_owner');
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
        $garage = $this->resolveGarage($user);
        if (! $garage) {
            return response()->json(['message' => 'No garage profile found. Enroll as garage owner first.'], 404);
        }

        $isOwner = $garage->owner_id === $user->id;
        $tech = Technician::where('user_id', $user->id)->where('garage_id', $garage->id)->first();
        $roleContext = $isOwner ? 'owner' : 'technician';

        $today = Carbon::today();
        $bookings = GarageBooking::where('garage_id', $garage->id);
        if (! $isOwner && $tech) {
            $bookings->where('technician_id', $tech->id);
        }

        $stats = [
            'total_bookings' => (clone $bookings)->count(),
            'today_bookings' => (clone $bookings)->whereDate('scheduled_at', $today)->count(),
            'pending' => (clone $bookings)->where('status', 'pending')->count(),
            'confirmed' => (clone $bookings)->where('status', 'confirmed')->count(),
            'assigned' => (clone $bookings)->where('status', 'assigned')->count(),
            'in_progress' => (clone $bookings)->where('status', 'in_progress')->count(),
            'completed' => (clone $bookings)->where('status', 'completed')->count(),
            'cancelled' => (clone $bookings)->where('status', 'cancelled')->count(),
            'technicians' => $isOwner ? $garage->technicians()->where('status', 'active')->count() : null,
            'services' => $isOwner ? $garage->services()->where('status', 'active')->count() : null,
            'revenue' => $isOwner
                ? (float) GarageBooking::where('garage_id', $garage->id)->where('status', 'completed')->sum('amount')
                : null,
        ];

        $recentQuery = GarageBooking::with(['customer:id,name,email,phone', 'service:id,name,price', 'technician.user:id,name'])
            ->where('garage_id', $garage->id);
        if (! $isOwner && $tech) {
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
        $garage = $this->resolveGarage($request->user());
        if (! $garage) {
            return response()->json(['message' => 'No garage profile found.'], 404);
        }

        return response()->json([
            'garage' => $garage->loadCount(['services', 'technicians', 'bookings']),
        ]);
    }

    public function updateGarage(Request $request): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request->user());
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
        $garage = $this->resolveGarage($request->user());
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
        $garage = $this->requireOwnerGarage($request->user());
        $this->authorize('manageServices', $garage);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'nullable|numeric|min:0',
            'type' => 'required|in:fixed,estimate',
            'duration_minutes' => 'nullable|integer|min:15|max:1440',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $service = $garage->services()->create([
            ...$validated,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['service' => $service], 201);
    }

    public function updateService(Request $request, GarageService $service): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request->user());
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
        $garage = $this->requireOwnerGarage($request->user());
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
        $garage = $this->resolveGarage($request->user());
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
        $garage = $this->requireOwnerGarage($request->user());
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
        $garage = $this->requireOwnerGarage($request->user());
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
                $this->employment->ensureGarageMembership(
                    $garage,
                    $technician->user,
                    \App\Models\GarageMember::TYPE_TECHNICIAN
                );
            } else {
                $this->employment->endGarageMembership(
                    $garage,
                    $technician->user,
                    \App\Models\GarageMember::TYPE_TECHNICIAN
                );
            }
        }

        return response()->json(['technician' => $technician->fresh()->load('user:id,name,email,phone')]);
    }

    /** Customers derived from this garage's bookings (no separate CRM list). */
    public function customers(Request $request): JsonResponse
    {
        $garage = $this->requireOwnerGarage($request->user());

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
        $garage = $this->requireOwnerGarage($request->user());

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
        $garage = $this->resolveGarage($request->user());
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
        $garage = $this->requireOwnerGarage($request->user());
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
            $booking = GarageBooking::create([
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
        $isOwner = $garage && $garage->owner_id === $request->user()->id;

        $rules = [
            'status' => 'sometimes|in:'.implode(',', GarageBooking::STATUSES),
            'notes' => 'nullable|string|max:2000',
            'vehicle_reg' => 'nullable|string|max:50',
            'scheduled_at' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0',
        ];
        if ($isOwner) {
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
        if (! $isOwner) {
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

        if ($isOwner && array_key_exists('technician_id', $validated) && $validated['technician_id'] && ($validated['status'] ?? $booking->status) === 'pending') {
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

    private function resolveGarage(User $user): ?Garage
    {
        $garage = Garage::where('owner_id', $user->id)->first();
        if ($garage) {
            return $garage;
        }

        $membership = $user->garageMemberships()
            ->where('status', 'active')
            ->whereNull('left_at')
            ->with('garage')
            ->latest('id')
            ->first();
        if ($membership?->garage) {
            return $membership->garage;
        }

        $tech = Technician::where('user_id', $user->id)->first();

        return $tech?->garage;
    }

    private function requireOwnerGarage(User $user): Garage
    {
        $garage = Garage::where('owner_id', $user->id)->first();
        if ($garage) {
            return $garage;
        }

        $managed = $user->garageMemberships()
            ->whereIn('membership_type', ['owner', 'manager'])
            ->where('status', 'active')
            ->whereNull('left_at')
            ->with('garage')
            ->latest('id')
            ->first();

        abort_unless($managed?->garage, 403, 'Garage owner access required.');

        return $managed->garage;
    }

    private function isTechnicianOnly(User $user): bool
    {
        return $user->hasCapability('technician')
            && ! $user->hasCapability('garage_owner')
            && ! Garage::where('owner_id', $user->id)->exists();
    }
}
