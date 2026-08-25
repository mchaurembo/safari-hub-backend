<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesTransportFleet;
use App\Models\Booking;
use App\Models\CargoRequest;
use App\Models\Driver;
use App\Models\Garage;
use App\Models\GarageBooking;
use App\Models\Payment;
use App\Models\Technician;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Payments\PaymentStatuses;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ResolvesTransportFleet;

    /**
     * Role-scoped reports for the signed-in user.
     * Query: scope=owner|garage|customer|driver|technician, from, to
     */
    public function mine(Request $request): JsonResponse
    {
        $scope = $request->input('scope', 'customer');
        [$fromAt, $toAt, $from, $to] = $this->period($request);

        return match ($scope) {
            'owner' => $this->ownerReport($request, $fromAt, $toAt, $from, $to),
            'garage' => $this->garageReport($request, $fromAt, $toAt, $from, $to),
            'driver' => $this->driverReport($request, $fromAt, $toAt, $from, $to),
            'technician' => $this->technicianReport($request, $fromAt, $toAt, $from, $to),
            default => $this->customerReport($request, $fromAt, $toAt, $from, $to),
        };
    }

    /**
     * Detailed rows for a report metric (for card popups).
     * Query: scope, metric, from, to
     */
    public function details(Request $request): JsonResponse
    {
        $scope = $request->input('scope', 'customer');
        $metric = $request->input('metric');
        if (! $metric) {
            return response()->json(['message' => 'metric is required'], 422);
        }

        [$fromAt, $toAt, $from, $to] = $this->period($request);

        $payload = match ($scope) {
            'owner' => $this->ownerDetails($request, $metric, $fromAt, $toAt),
            'garage', 'technician' => $this->garageDetails($request, $metric, $fromAt, $toAt),
            'driver' => $this->driverDetails($request, $metric, $fromAt, $toAt),
            'admin' => $this->adminDetails($request, $metric, $fromAt, $toAt),
            default => $this->customerDetails($request, $metric, $fromAt, $toAt),
        };

        if ($payload === null) {
            return response()->json(['message' => 'Unknown metric or no access'], 404);
        }

        return response()->json([
            'data' => array_merge($payload, [
                'scope' => $scope,
                'metric' => $metric,
                'period' => ['from' => $from, 'to' => $to],
            ]),
        ]);
    }

    private function period(Request $request): array
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $fromAt = Carbon::parse($from)->startOfDay();
        $toAt = Carbon::parse($to)->endOfDay();

        return [$fromAt, $toAt, $fromAt->toDateString(), $toAt->toDateString()];
    }

    private function ownerReport(Request $request, Carbon $fromAt, Carbon $toAt, string $from, string $to): JsonResponse
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return response()->json(['message' => 'No fleet access'], 403);
        }

        $driverIds = Driver::where('owner_id', $owner->id)->pluck('id');
        $vehicleIds = Vehicle::where('owner_id', $owner->id)->pluck('id');

        $cargo = CargoRequest::query()
            ->whereIn('driver_id', $driverIds)
            ->whereIn('status', ['accepted', 'in_progress', 'delivered', 'completed'])
            ->whereNotNull('quoted_price')
            ->whereBetween('updated_at', [$fromAt, $toAt])
            ->with(['driver.user:id,name'])
            ->get();

        $tripIds = Trip::whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->pluck('id');
        $bookingIds = Booking::whereIn('trip_id', $tripIds)
            ->whereIn('status', ['paid', 'completed'])
            ->pluck('id');
        $passengerRevenue = (float) Payment::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('status', PaymentStatuses::successStates())
            ->sum('amount');

        $cargoEarnings = (float) $cargo->sum('quoted_price');
        $byDriver = $cargo->groupBy('driver_id')->map(function ($rows, $driverId) {
            $first = $rows->first();

            return [
                'driver_id' => $driverId,
                'driver_name' => $first->driver?->user?->name ?? 'Unknown',
                'trips' => $rows->count(),
                'total' => (float) $rows->sum('quoted_price'),
            ];
        })->values();

        return response()->json([
            'data' => [
                'scope' => 'owner',
                'title' => 'Fleet reports',
                'period' => ['from' => $from, 'to' => $to],
                'finance' => [
                    'cargo_earnings' => $cargoEarnings,
                    'passenger_revenue' => $passengerRevenue,
                    'total_revenue' => $cargoEarnings + $passengerRevenue,
                ],
                'activity' => [
                    'cargo_trips' => $cargo->count(),
                    'passenger_trips' => $tripIds->count(),
                    'paid_bookings' => $bookingIds->count(),
                ],
                'snapshot' => [
                    'vehicles' => $vehicleIds->count(),
                    'drivers' => $driverIds->count(),
                ],
                'by_driver' => $byDriver,
            ],
        ]);
    }

    private function garageReport(Request $request, Carbon $fromAt, Carbon $toAt, string $from, string $to): JsonResponse
    {
        $user = $request->user();
        $garage = $this->resolveUserGarage($request);
        if (! $garage) {
            return response()->json(['message' => 'No garage found'], 404);
        }

        $canManage = $user->ownsGarage($garage);
        $tech = Technician::where('user_id', $user->id)->where('garage_id', $garage->id)->first();

        $bookings = GarageBooking::query()->where('garage_id', $garage->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);
        if (! $canManage && $tech) {
            $bookings->where('technician_id', $tech->id);
        }

        $completed = (clone $bookings)->where('status', 'completed');
        $revenue = $canManage
            ? (float) (clone $completed)->sum('amount')
            : null;

        $workOrders = WorkOrder::query()
            ->where('garage_id', $garage->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);
        if (! $canManage && $tech) {
            $workOrders->where('technician_id', $tech->id);
        }

        return response()->json([
            'data' => [
                'scope' => $canManage ? 'garage' : 'technician',
                'title' => $canManage ? 'Garage reports' : 'My job reports',
                'period' => ['from' => $from, 'to' => $to],
                'finance' => $canManage ? [
                    'total_revenue' => $revenue,
                    'completed_bookings' => (clone $completed)->count(),
                ] : null,
                'activity' => [
                    'bookings' => (clone $bookings)->count(),
                    'pending' => (clone $bookings)->where('status', 'pending')->count(),
                    'in_progress' => (clone $bookings)->where('status', 'in_progress')->count(),
                    'completed' => (clone $completed)->count(),
                    'cancelled' => (clone $bookings)->where('status', 'cancelled')->count(),
                    'work_orders' => (clone $workOrders)->count(),
                    'work_orders_completed' => (clone $workOrders)->where('status', 'completed')->count(),
                ],
                'snapshot' => $canManage ? [
                    'technicians' => $garage->technicians()->where('status', 'active')->count(),
                    'services' => $garage->services()->where('status', 'active')->count(),
                ] : null,
            ],
        ]);
    }

    private function customerReport(Request $request, Carbon $fromAt, Carbon $toAt, string $from, string $to): JsonResponse
    {
        $user = $request->user();

        $payments = Payment::query()
            ->where('payer_id', $user->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);
        $spent = (float) (clone $payments)->whereIn('status', PaymentStatuses::successStates())->sum('amount');
        $pending = (clone $payments)->whereIn('status', [
            PaymentStatuses::INITIATED,
            PaymentStatuses::PENDING,
            PaymentStatuses::PROCESSING,
            PaymentStatuses::LEGACY_PENDING,
        ])->count();

        $bookings = Booking::query()
            ->where('customer_id', $user->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);
        $cargo = CargoRequest::query()
            ->where('customer_id', $user->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);
        $garageBookings = GarageBooking::query()
            ->where('customer_id', $user->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);

        return response()->json([
            'data' => [
                'scope' => 'customer',
                'title' => 'My activity',
                'period' => ['from' => $from, 'to' => $to],
                'finance' => [
                    'total_spent' => $spent,
                    'successful_payments' => (clone $payments)->whereIn('status', PaymentStatuses::successStates())->count(),
                    'pending_payments' => $pending,
                ],
                'activity' => [
                    'transport_bookings' => (clone $bookings)->count(),
                    'cargo_requests' => (clone $cargo)->count(),
                    'garage_bookings' => (clone $garageBookings)->count(),
                ],
            ],
        ]);
    }

    private function driverReport(Request $request, Carbon $fromAt, Carbon $toAt, string $from, string $to): JsonResponse
    {
        $user = $request->user();
        $driver = Driver::where('user_id', $user->id)->first();
        if (! $driver) {
            return response()->json(['message' => 'No driver profile'], 404);
        }

        $cargo = CargoRequest::query()
            ->where('driver_id', $driver->id)
            ->whereBetween('updated_at', [$fromAt, $toAt]);
        $completed = (clone $cargo)->whereIn('status', ['delivered', 'completed']);
        $earnings = (float) (clone $completed)->whereNotNull('quoted_price')->sum('quoted_price');

        $trips = Trip::query()
            ->where('driver_id', $driver->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);

        return response()->json([
            'data' => [
                'scope' => 'driver',
                'title' => 'My trip reports',
                'period' => ['from' => $from, 'to' => $to],
                'finance' => [
                    'cargo_earnings' => $earnings,
                ],
                'activity' => [
                    'cargo_jobs' => (clone $cargo)->count(),
                    'cargo_completed' => (clone $completed)->count(),
                    'passenger_trips' => (clone $trips)->count(),
                ],
            ],
        ]);
    }

    private function technicianReport(Request $request, Carbon $fromAt, Carbon $toAt, string $from, string $to): JsonResponse
    {
        return $this->garageReport($request, $fromAt, $toAt, $from, $to);
    }

    private function ownerDetails(Request $request, string $metric, Carbon $fromAt, Carbon $toAt): ?array
    {
        $owner = $this->transportFleet($request);
        if (! $owner) {
            return null;
        }
        $driverIds = Driver::where('owner_id', $owner->id)->pluck('id');
        $vehicleIds = Vehicle::where('owner_id', $owner->id)->pluck('id');

        if (in_array($metric, ['cargo_earnings', 'cargo_trips', 'total_revenue'], true)) {
            $rows = CargoRequest::query()
                ->whereIn('driver_id', $driverIds)
                ->whereIn('status', ['accepted', 'in_progress', 'delivered', 'completed'])
                ->whereNotNull('quoted_price')
                ->whereBetween('updated_at', [$fromAt, $toAt])
                ->with(['driver.user:id,name', 'customer:id,name', 'vehicle:id,vehicle_number'])
                ->orderByDesc('updated_at')
                ->limit(200)
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => optional($r->updated_at)->toDateTimeString(),
                    'driver' => $r->driver?->user?->name ?? '—',
                    'customer' => $r->customer?->name ?? '—',
                    'vehicle' => $r->vehicle?->vehicle_number ?? '—',
                    'status' => $r->status,
                    'amount' => (float) $r->quoted_price,
                ]);

            return [
                'title' => 'Cargo trips',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'driver', 'title' => 'Driver'],
                    ['key' => 'customer', 'title' => 'Customer'],
                    ['key' => 'vehicle', 'title' => 'Vehicle'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'passenger_trips') {
            $rows = Trip::query()
                ->whereIn('vehicle_id', $vehicleIds)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['route:id,origin,destination', 'vehicle:id,vehicle_number', 'driver.user:id,name'])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => optional($t->created_at)->toDateTimeString(),
                    'route' => $t->route ? ($t->route->origin.' → '.$t->route->destination) : '—',
                    'vehicle' => $t->vehicle?->vehicle_number ?? '—',
                    'driver' => $t->driver?->user?->name ?? '—',
                    'departure' => optional($t->departure_time)->toDateTimeString() ?? '—',
                    'status' => $t->status ?? '—',
                ]);

            return [
                'title' => 'Passenger trips',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'route', 'title' => 'Route'],
                    ['key' => 'vehicle', 'title' => 'Vehicle'],
                    ['key' => 'driver', 'title' => 'Driver'],
                    ['key' => 'departure', 'title' => 'Departure'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if (in_array($metric, ['passenger_revenue', 'paid_bookings'], true)) {
            $tripIds = Trip::whereIn('vehicle_id', $vehicleIds)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->pluck('id');
            $rows = Booking::query()
                ->whereIn('trip_id', $tripIds)
                ->whereIn('status', ['paid', 'completed'])
                ->with(['customer:id,name,phone', 'trip.route:id,origin,destination', 'trip.vehicle:id,vehicle_number'])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'date' => optional($b->created_at)->toDateTimeString(),
                    'reference' => $b->booking_reference,
                    'customer' => $b->customer?->name ?? '—',
                    'route' => $b->trip?->route
                        ? ($b->trip->route->origin.' → '.$b->trip->route->destination)
                        : '—',
                    'vehicle' => $b->trip?->vehicle?->vehicle_number ?? '—',
                    'status' => $b->status,
                ]);

            return [
                'title' => 'Paid passenger bookings',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'reference', 'title' => 'Reference'],
                    ['key' => 'customer', 'title' => 'Customer'],
                    ['key' => 'route', 'title' => 'Route'],
                    ['key' => 'vehicle', 'title' => 'Vehicle'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'drivers') {
            $rows = Driver::where('owner_id', $owner->id)
                ->with('user:id,name,phone,email')
                ->orderBy('id')
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->user?->name ?? '—',
                    'phone' => $d->user?->phone ?? '—',
                    'email' => $d->user?->email ?? '—',
                    'status' => $d->status ?? '—',
                ]);

            return [
                'title' => 'Drivers',
                'columns' => [
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'phone', 'title' => 'Phone'],
                    ['key' => 'email', 'title' => 'Email'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'vehicles') {
            $rows = Vehicle::where('owner_id', $owner->id)
                ->orderBy('vehicle_number')
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'number' => $v->vehicle_number,
                    'type' => $v->vehicle_type ?? '—',
                    'model' => $v->model ?? '—',
                    'status' => $v->status ?? '—',
                ]);

            return [
                'title' => 'Vehicles',
                'columns' => [
                    ['key' => 'number', 'title' => 'Number'],
                    ['key' => 'type', 'title' => 'Type'],
                    ['key' => 'model', 'title' => 'Model'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        return null;
    }

    private function garageDetails(Request $request, string $metric, Carbon $fromAt, Carbon $toAt): ?array
    {
        $user = $request->user();
        $garage = $this->resolveUserGarage($request);
        if (! $garage) {
            return null;
        }
        $canManage = $user->ownsGarage($garage);
        $tech = Technician::where('user_id', $user->id)->where('garage_id', $garage->id)->first();

        if (in_array($metric, [
            'total_revenue', 'completed_bookings', 'bookings', 'pending', 'in_progress',
            'completed', 'cancelled',
        ], true)) {
            $q = GarageBooking::query()
                ->where('garage_id', $garage->id)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['customer:id,name,phone', 'service:id,name', 'technician.user:id,name']);
            if (! $canManage && $tech) {
                $q->where('technician_id', $tech->id);
            }
            if (in_array($metric, ['completed', 'completed_bookings', 'total_revenue'], true)) {
                $q->where('status', 'completed');
            } elseif (in_array($metric, ['pending', 'in_progress', 'cancelled'], true)) {
                $q->where('status', $metric);
            }

            $rows = $q->orderByDesc('created_at')->limit(200)->get()->map(fn ($b) => [
                'id' => $b->id,
                'date' => optional($b->created_at)->toDateTimeString(),
                'customer' => $b->customer?->name ?? '—',
                'service' => $b->service?->name ?? '—',
                'technician' => $b->technician?->user?->name ?? '—',
                'status' => $b->status,
                'amount' => (float) ($b->amount ?? 0),
            ]);

            return [
                'title' => 'Garage bookings',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'customer', 'title' => 'Customer'],
                    ['key' => 'service', 'title' => 'Service'],
                    ['key' => 'technician', 'title' => 'Technician'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if (in_array($metric, ['work_orders', 'work_orders_completed'], true)) {
            $q = WorkOrder::query()
                ->where('garage_id', $garage->id)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['technician.user:id,name']);
            if (! $canManage && $tech) {
                $q->where('technician_id', $tech->id);
            }
            if ($metric === 'work_orders_completed') {
                $q->where('status', 'completed');
            }
            $rows = $q->orderByDesc('created_at')->limit(200)->get()->map(fn ($w) => [
                'id' => $w->id,
                'date' => optional($w->created_at)->toDateTimeString(),
                'technician' => $w->technician?->user?->name ?? '—',
                'status' => $w->status,
                'notes' => $w->notes ?? '—',
            ]);

            return [
                'title' => 'Work orders',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'technician', 'title' => 'Technician'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'notes', 'title' => 'Notes'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'technicians' && $canManage) {
            $rows = $garage->technicians()->with('user:id,name,phone')->where('status', 'active')->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->user?->name ?? '—',
                    'phone' => $t->user?->phone ?? '—',
                    'status' => $t->status,
                ]);

            return [
                'title' => 'Technicians',
                'columns' => [
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'phone', 'title' => 'Phone'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'services' && $canManage) {
            $rows = $garage->services()->where('status', 'active')->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'price' => (float) ($s->price ?? 0),
                    'type' => $s->type ?? '—',
                ]);

            return [
                'title' => 'Services',
                'columns' => [
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'price', 'title' => 'Price (TZS)', 'money' => true],
                    ['key' => 'type', 'title' => 'Type'],
                ],
                'rows' => $rows,
            ];
        }

        return null;
    }

    private function customerDetails(Request $request, string $metric, Carbon $fromAt, Carbon $toAt): ?array
    {
        $user = $request->user();

        if (in_array($metric, ['total_spent', 'successful_payments', 'pending_payments', 'average_payment'], true)) {
            $q = Payment::query()
                ->where('payer_id', $user->id)
                ->whereBetween('created_at', [$fromAt, $toAt]);
            if ($metric === 'successful_payments' || $metric === 'total_spent' || $metric === 'average_payment') {
                $q->whereIn('status', PaymentStatuses::successStates());
            } elseif ($metric === 'pending_payments') {
                $q->whereIn('status', [
                    PaymentStatuses::INITIATED,
                    PaymentStatuses::PENDING,
                    PaymentStatuses::PROCESSING,
                    PaymentStatuses::LEGACY_PENDING,
                ]);
            }
            $rows = $q->orderByDesc('created_at')->limit(200)->get()->map(fn ($p) => [
                'id' => $p->id,
                'date' => optional($p->created_at)->toDateTimeString(),
                'reference' => $p->payment_reference ?? $p->id,
                'status' => $p->status,
                'amount' => (float) $p->amount,
            ]);

            return [
                'title' => 'Payments',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'reference', 'title' => 'Reference'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'transport_bookings') {
            $rows = Booking::query()
                ->where('customer_id', $user->id)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['trip.route:id,origin,destination'])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'date' => optional($b->created_at)->toDateTimeString(),
                    'reference' => $b->booking_reference,
                    'route' => $b->trip?->route
                        ? ($b->trip->route->origin.' → '.$b->trip->route->destination)
                        : '—',
                    'status' => $b->status,
                ]);

            return [
                'title' => 'Transport bookings',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'reference', 'title' => 'Reference'],
                    ['key' => 'route', 'title' => 'Route'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'cargo_requests') {
            $rows = CargoRequest::query()
                ->where('customer_id', $user->id)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => optional($r->created_at)->toDateTimeString(),
                    'pickup' => $r->pickup_address ?? '—',
                    'destination' => $r->dest_address ?? '—',
                    'status' => $r->status,
                    'amount' => (float) ($r->quoted_price ?? 0),
                ]);

            return [
                'title' => 'Cargo requests',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'pickup', 'title' => 'Pickup'],
                    ['key' => 'destination', 'title' => 'Destination'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'garage_bookings') {
            $rows = GarageBooking::query()
                ->where('customer_id', $user->id)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['service:id,name', 'garage:id,name'])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'date' => optional($b->created_at)->toDateTimeString(),
                    'garage' => $b->garage?->name ?? '—',
                    'service' => $b->service?->name ?? '—',
                    'status' => $b->status,
                    'amount' => (float) ($b->amount ?? 0),
                ]);

            return [
                'title' => 'Garage bookings',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'garage', 'title' => 'Garage'],
                    ['key' => 'service', 'title' => 'Service'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        return null;
    }

    private function driverDetails(Request $request, string $metric, Carbon $fromAt, Carbon $toAt): ?array
    {
        $driver = Driver::where('user_id', $request->user()->id)->first();
        if (! $driver) {
            return null;
        }

        if (in_array($metric, ['cargo_earnings', 'cargo_jobs', 'cargo_completed'], true)) {
            $q = CargoRequest::query()
                ->where('driver_id', $driver->id)
                ->whereBetween('updated_at', [$fromAt, $toAt])
                ->with(['customer:id,name']);
            if ($metric === 'cargo_completed' || $metric === 'cargo_earnings') {
                $q->whereIn('status', ['delivered', 'completed']);
            }
            $rows = $q->orderByDesc('updated_at')->limit(200)->get()->map(fn ($r) => [
                'id' => $r->id,
                'date' => optional($r->updated_at)->toDateTimeString(),
                'customer' => $r->customer?->name ?? '—',
                'pickup' => $r->pickup_address ?? '—',
                'destination' => $r->dest_address ?? '—',
                'status' => $r->status,
                'amount' => (float) ($r->quoted_price ?? 0),
            ]);

            return [
                'title' => 'Cargo jobs',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'customer', 'title' => 'Customer'],
                    ['key' => 'pickup', 'title' => 'Pickup'],
                    ['key' => 'destination', 'title' => 'Destination'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'passenger_trips') {
            $rows = Trip::query()
                ->where('driver_id', $driver->id)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['route:id,origin,destination', 'vehicle:id,vehicle_number'])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => optional($t->created_at)->toDateTimeString(),
                    'route' => $t->route ? ($t->route->origin.' → '.$t->route->destination) : '—',
                    'vehicle' => $t->vehicle?->vehicle_number ?? '—',
                    'departure' => optional($t->departure_time)->toDateTimeString() ?? '—',
                ]);

            return [
                'title' => 'Passenger trips',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'route', 'title' => 'Route'],
                    ['key' => 'vehicle', 'title' => 'Vehicle'],
                    ['key' => 'departure', 'title' => 'Departure'],
                ],
                'rows' => $rows,
            ];
        }

        return null;
    }

    private function adminDetails(Request $request, string $metric, Carbon $fromAt, Carbon $toAt): ?array
    {
        if (! $request->user()?->hasCapability('admin')) {
            return null;
        }

        if (in_array($metric, [
            'total_revenue', 'successful_payments', 'pending_payments', 'average_payment',
        ], true)) {
            $q = Payment::query()->whereBetween('created_at', [$fromAt, $toAt]);
            if (in_array($metric, ['total_revenue', 'successful_payments', 'average_payment'], true)) {
                $q->successful();
            } else {
                $q->whereIn('status', [
                    PaymentStatuses::INITIATED,
                    PaymentStatuses::PENDING,
                    PaymentStatuses::PROCESSING,
                    PaymentStatuses::LEGACY_PENDING,
                ]);
            }
            $rows = $q->with('payer:id,name,email')->orderByDesc('created_at')->limit(200)->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'date' => optional($p->created_at)->toDateTimeString(),
                    'payer' => $p->payer?->name ?? '—',
                    'reference' => $p->payment_reference ?? $p->id,
                    'status' => $p->status,
                    'amount' => (float) $p->amount,
                ]);

            return [
                'title' => 'Payments',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'payer', 'title' => 'Payer'],
                    ['key' => 'reference', 'title' => 'Reference'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if (in_array($metric, ['bookings_paid', 'bookings_created', 'paid_bookings'], true)) {
            $q = Booking::query()->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['customer:id,name', 'trip.route:id,origin,destination']);
            if ($metric === 'bookings_paid' || $metric === 'paid_bookings') {
                $q->whereIn('status', ['paid', 'completed']);
            }
            $rows = $q->orderByDesc('created_at')->limit(200)->get()->map(fn ($b) => [
                'id' => $b->id,
                'date' => optional($b->created_at)->toDateTimeString(),
                'customer' => $b->customer?->name ?? '—',
                'reference' => $b->booking_reference,
                'route' => $b->trip?->route
                    ? ($b->trip->route->origin.' → '.$b->trip->route->destination)
                    : '—',
                'status' => $b->status,
            ]);

            return [
                'title' => 'Bookings',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'customer', 'title' => 'Customer'],
                    ['key' => 'reference', 'title' => 'Reference'],
                    ['key' => 'route', 'title' => 'Route'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'trips') {
            $rows = Trip::query()->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['route:id,origin,destination', 'vehicle:id,vehicle_number'])
                ->orderByDesc('created_at')->limit(200)->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => optional($t->created_at)->toDateTimeString(),
                    'route' => $t->route ? ($t->route->origin.' → '.$t->route->destination) : '—',
                    'vehicle' => $t->vehicle?->vehicle_number ?? '—',
                ]);

            return [
                'title' => 'Trips',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'route', 'title' => 'Route'],
                    ['key' => 'vehicle', 'title' => 'Vehicle'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'cargo_requests') {
            $rows = CargoRequest::query()->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['customer:id,name'])
                ->orderByDesc('created_at')->limit(200)->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => optional($r->created_at)->toDateTimeString(),
                    'customer' => $r->customer?->name ?? '—',
                    'status' => $r->status,
                    'amount' => (float) ($r->quoted_price ?? 0),
                ]);

            return [
                'title' => 'Cargo requests',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'customer', 'title' => 'Customer'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if (in_array($metric, ['garage_bookings', 'work_orders'], true)) {
            if ($metric === 'work_orders') {
                $rows = WorkOrder::query()->whereBetween('created_at', [$fromAt, $toAt])
                    ->orderByDesc('created_at')->limit(200)->get()
                    ->map(fn ($w) => [
                        'id' => $w->id,
                        'date' => optional($w->created_at)->toDateTimeString(),
                        'status' => $w->status,
                    ]);

                return [
                    'title' => 'Work orders',
                    'columns' => [
                        ['key' => 'date', 'title' => 'Date'],
                        ['key' => 'status', 'title' => 'Status'],
                        ['key' => 'id', 'title' => 'ID'],
                    ],
                    'rows' => $rows,
                ];
            }
            $rows = GarageBooking::query()->whereBetween('created_at', [$fromAt, $toAt])
                ->with(['customer:id,name', 'garage:id,name'])
                ->orderByDesc('created_at')->limit(200)->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'date' => optional($b->created_at)->toDateTimeString(),
                    'garage' => $b->garage?->name ?? '—',
                    'customer' => $b->customer?->name ?? '—',
                    'status' => $b->status,
                    'amount' => (float) ($b->amount ?? 0),
                ]);

            return [
                'title' => 'Garage bookings',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'garage', 'title' => 'Garage'],
                    ['key' => 'customer', 'title' => 'Customer'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'amount', 'title' => 'Amount (TZS)', 'money' => true],
                ],
                'rows' => $rows,
            ];
        }

        if (in_array($metric, ['new_users', 'total_users'], true)) {
            $q = \App\Models\User::query()->orderByDesc('created_at');
            if ($metric === 'new_users') {
                $q->whereBetween('created_at', [$fromAt, $toAt]);
            } else {
                $q->where('status', '!=', 'inactive');
            }
            $rows = $q->limit(200)->get()->map(fn ($u) => [
                'id' => $u->id,
                'date' => optional($u->created_at)->toDateTimeString(),
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone ?? '—',
                'status' => $u->status,
            ]);

            return [
                'title' => 'Users',
                'columns' => [
                    ['key' => 'date', 'title' => 'Joined'],
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'email', 'title' => 'Email'],
                    ['key' => 'phone', 'title' => 'Phone'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'complaints') {
            $rows = \App\Models\Complaint::query()
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'date' => optional($c->created_at)->toDateTimeString(),
                    'user' => $c->user?->name ?? '—',
                    'status' => $c->status,
                    'message' => $c->message,
                ]);

            return [
                'title' => 'Complaints',
                'columns' => [
                    ['key' => 'date', 'title' => 'Date'],
                    ['key' => 'user', 'title' => 'User'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'message', 'title' => 'Message'],
                ],
                'rows' => $rows,
            ];
        }

        if (in_array($metric, ['active_owners', 'pending_owners'], true)) {
            $status = $metric === 'pending_owners' ? 'pending' : 'approved';
            $rows = \App\Models\TransportOwner::query()
                ->where('status', $status)
                ->with('user:id,name,email,phone')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'name' => $o->user?->name ?? $o->company_name ?? '—',
                    'company' => $o->company_name ?? '—',
                    'email' => $o->user?->email ?? '—',
                    'status' => $o->status,
                ]);

            return [
                'title' => $metric === 'pending_owners' ? 'Pending owners' : 'Transport owners',
                'columns' => [
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'company', 'title' => 'Company'],
                    ['key' => 'email', 'title' => 'Email'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'active_drivers') {
            $rows = Driver::query()->with('user:id,name,phone,email')->orderByDesc('id')->limit(200)->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->user?->name ?? '—',
                    'phone' => $d->user?->phone ?? '—',
                    'email' => $d->user?->email ?? '—',
                ]);

            return [
                'title' => 'Drivers',
                'columns' => [
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'phone', 'title' => 'Phone'],
                    ['key' => 'email', 'title' => 'Email'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'garages') {
            $rows = Garage::query()->with('owner:id,name')->orderBy('name')->limit(200)->get()
                ->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'owner' => $g->owner?->name ?? '—',
                    'location' => $g->location ?? '—',
                    'status' => $g->status ?? '—',
                ]);

            return [
                'title' => 'Garages',
                'columns' => [
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'owner', 'title' => 'Owner'],
                    ['key' => 'location', 'title' => 'Location'],
                    ['key' => 'status', 'title' => 'Status'],
                ],
                'rows' => $rows,
            ];
        }

        if ($metric === 'businesses') {
            $rows = \App\Models\Business::query()->orderBy('trade_name')->limit(200)->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->trade_name ?? $b->legal_name ?? '—',
                    'status' => $b->status ?? '—',
                ]);

            return [
                'title' => 'Businesses',
                'columns' => [
                    ['key' => 'name', 'title' => 'Name'],
                    ['key' => 'status', 'title' => 'Status'],
                    ['key' => 'id', 'title' => 'ID'],
                ],
                'rows' => $rows,
            ];
        }

        return null;
    }

    private function resolveUserGarage(Request $request): ?Garage
    {
        $user = $request->user();
        $owned = $user->garages()->first();
        if ($owned) {
            return $owned;
        }
        $membershipGarageId = $user->garageMemberships()
            ->where('status', 'active')
            ->whereNull('left_at')
            ->value('garage_id');
        if ($membershipGarageId) {
            return Garage::find($membershipGarageId);
        }
        $tech = Technician::where('user_id', $user->id)->with('garage')->first();

        return $tech?->garage;
    }
}
