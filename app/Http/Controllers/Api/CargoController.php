<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CargoRequest;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\Vehicle;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CargoController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    // Haversine formula — distance in km between two lat/lng points
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // GET /cargo/nearby-drivers?lat=&lng=&radius=50&all=false&include_offline=false
    public function nearbyDrivers(Request $request): JsonResponse
    {
        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');
        $radius = (float) $request->input('radius', 50);
        $all = filter_var($request->input('all', false), FILTER_VALIDATE_BOOLEAN);
        $includeOffline = filter_var($request->input('include_offline', false), FILTER_VALIDATE_BOOLEAN);

        // --- Drivers who have a location record ---
        $locationsQuery = DriverLocation::with(['driver.user', 'driver.owner', 'driver.vehicles']);

        if (! $includeOffline) {
            $locationsQuery->where('is_available', true);
        }

        $located = $locationsQuery->get()
            ->filter(function ($loc) use ($lat, $lng, $radius, $all) {
                $driver = $loc->driver;
                if (! $driver || $driver->status !== 'active' || ! $driver->owner_id) {
                    return false;
                }

                $vehicles = $this->resolveCargoVehicles($driver);
                if ($vehicles->isEmpty()) {
                    return false;
                }
                $driver->setRelation('vehicles', $vehicles);

                $dist = $this->haversine($lat, $lng, (float) $loc->latitude, (float) $loc->longitude);
                $loc->distance_km = round($dist, 2);

                return $all || $dist <= $radius;
            })
            ->sortBy('distance_km')
            ->values();

        // --- Drivers with NO location record (never set location) ---
        $locatedDriverIds = $located->pluck('driver_id')->toArray();

        $noLocation = collect();
        if ($includeOffline) {
            $noLocation = Driver::with(['user', 'owner', 'vehicles'])
                ->whereNotNull('owner_id')
                ->where('status', 'active')
                ->whereNotIn('id', $locatedDriverIds)
                ->get()
                ->filter(function ($driver) {
                    $vehicles = $this->resolveCargoVehicles($driver);
                    if ($vehicles->isEmpty()) {
                        return false;
                    }
                    $driver->setRelation('vehicles', $vehicles);

                    return true;
                })
                ->map(fn ($driver) => [
                    'id' => null,
                    'driver_id' => $driver->id,
                    'driver' => $driver,
                    'latitude' => null,
                    'longitude' => null,
                    'is_available' => false,
                    'distance_km' => null,
                ])
                ->values();
        }

        // Normalise located entries to arrays too so the collection is uniform
        $locatedArray = $located->map(fn ($loc) => [
            'id' => $loc->id,
            'driver_id' => $loc->driver_id,
            'driver' => $loc->driver,
            'latitude' => $loc->latitude,
            'longitude' => $loc->longitude,
            'is_available' => $loc->is_available,
            'distance_km' => $loc->distance_km,
        ])->values();

        $result = $locatedArray->concat($noLocation)->values();

        return response()->json(['data' => $result]);
    }

    /**
     * Prefer vehicles assigned to the driver; otherwise use active cargo vehicles from their fleet.
     */
    private function resolveCargoVehicles(Driver $driver)
    {
        $assigned = $driver->vehicles
            ->where('transport_type', 'cargo')
            ->where('status', 'active')
            ->values();

        if ($assigned->isNotEmpty()) {
            return $assigned;
        }

        if (! $driver->owner_id) {
            return collect();
        }

        return Vehicle::query()
            ->where('owner_id', $driver->owner_id)
            ->where('transport_type', 'cargo')
            ->where('status', 'active')
            ->get();
    }

    // POST /cargo/requests — customer creates a request
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => 'required|exists:drivers,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'pickup_lat' => 'required|numeric',
            'pickup_lng' => 'required|numeric',
            'pickup_address' => 'required|string',
            'dest_lat' => 'required|numeric',
            'dest_lng' => 'required|numeric',
            'dest_address' => 'required|string',
            'cargo_description' => 'required|string|max:500',
            'weight_kg' => 'nullable|numeric|min:0',
            'customer_budget' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            // Road distance from client map (OSRM); preferred over straight-line haversine.
            'distance_km' => 'nullable|numeric|min:0.1|max:5000',
        ]);

        $straightLine = $this->haversine(
            (float) $validated['pickup_lat'], (float) $validated['pickup_lng'],
            (float) $validated['dest_lat'], (float) $validated['dest_lng']
        );

        $clientDistance = isset($validated['distance_km']) ? (float) $validated['distance_km'] : null;
        unset($validated['distance_km']);

        // Prefer road distance from the map when it is plausible vs straight-line.
        $distance = $straightLine;
        if ($clientDistance !== null) {
            $minAllowed = max(0.1, $straightLine * 0.85);
            $maxAllowed = max($straightLine * 4, $straightLine + 50);
            if ($clientDistance >= $minAllowed && $clientDistance <= $maxAllowed) {
                $distance = $clientDistance;
            }
        }

        $cargo = CargoRequest::create([
            ...$validated,
            'customer_id' => $request->user()->id,
            'distance_km' => round($distance, 2),
            'status' => 'pending',
        ]);

        $cargo->load(['driver.user', 'vehicle']);
        $driver = $cargo->driver;
        $customer = $request->user();

        $this->notify->driverNewRequest(
            driverName: $driver->user->name,
            driverEmail: $driver->user->email,
            driverPhone: $driver->user->phone,
            customerName: $customer->name,
            pickupAddress: $cargo->pickup_address,
            destAddress: $cargo->dest_address,
            distanceKm: (float) $cargo->distance_km,
            cargoDescription: $cargo->cargo_description,
            customerBudget: $cargo->customer_budget ? (float) $cargo->customer_budget : null,
            driverWhatsapp: $driver->user->whatsapp_number,
        );

        return response()->json(['data' => $cargo], 201);
    }

    // GET /cargo/my-requests — customer's own requests
    public function myRequests(Request $request): JsonResponse
    {
        $requests = CargoRequest::with(['driver.user', 'driver.location', 'vehicle', 'payment'])
            ->where('customer_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

    // POST /cargo/requests/{cargo}/accept-quote — customer accepts driver's quoted price
    public function acceptQuote(Request $request, CargoRequest $cargo): JsonResponse
    {
        if ($cargo->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($cargo->status !== 'quoted') {
            return response()->json(['message' => 'No quote to accept'], 422);
        }

        $cargo->update(['status' => 'accepted']);
        $cargo->load(['driver.user', 'vehicle']);

        $this->notify->driverQuoteAccepted(
            driverName: $cargo->driver->user->name,
            driverEmail: $cargo->driver->user->email,
            driverPhone: $cargo->driver->user->phone,
            customerName: $request->user()->name,
            pickupAddress: $cargo->pickup_address,
            destAddress: $cargo->dest_address,
            quotedPrice: (float) $cargo->quoted_price,
            driverWhatsapp: $cargo->driver->user->whatsapp_number,
        );

        return response()->json(['data' => $cargo]);
    }

    // POST /cargo/requests/{cargo}/decline-quote — customer declines driver's quote
    public function declineQuote(Request $request, CargoRequest $cargo): JsonResponse
    {
        if ($cargo->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($cargo->status !== 'quoted') {
            return response()->json(['message' => 'No quote to decline'], 422);
        }

        $cargo->update(['status' => 'declined']);
        $cargo->load(['driver.user']);

        $this->notify->driverQuoteDeclined(
            driverName: $cargo->driver->user->name,
            driverEmail: $cargo->driver->user->email,
            driverPhone: $cargo->driver->user->phone,
            customerName: $request->user()->name,
            pickupAddress: $cargo->pickup_address,
            destAddress: $cargo->dest_address,
            quotedPrice: (float) $cargo->quoted_price,
            driverWhatsapp: $cargo->driver->user->whatsapp_number,
        );

        return response()->json(['data' => $cargo]);
    }

    // POST /cargo/requests/{cargo}/cancel — customer cancels pending request
    public function cancel(Request $request, CargoRequest $cargo): JsonResponse
    {
        if ($cargo->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if (! in_array($cargo->status, ['pending', 'quoted'])) {
            return response()->json(['message' => 'Cannot cancel at this stage'], 422);
        }

        $cargo->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Request cancelled']);
    }

    // POST /cargo/requests/{cargo}/confirm-delivery — customer confirms delivery
    public function confirmDelivery(Request $request, CargoRequest $cargo): JsonResponse
    {
        if ($cargo->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($cargo->status !== 'delivered') {
            return response()->json(['message' => 'Not yet delivered'], 422);
        }

        $cargo->update(['status' => 'completed']);

        return response()->json(['data' => $cargo->fresh()]);
    }

    // --- DRIVER SIDE ---

    // GET /cargo/driver-requests — driver sees incoming requests
    public function driverRequests(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;
        if (! $driver) {
            return response()->json(['message' => 'Not a driver'], 403);
        }

        $requests = CargoRequest::with(['customer', 'vehicle'])
            ->where('driver_id', $driver->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

    // POST /cargo/requests/{cargo}/quote — driver submits a price quote
    public function quote(Request $request, CargoRequest $cargo): JsonResponse
    {
        $driver = $request->user()->driver;
        if (! $driver || $cargo->driver_id !== $driver->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($cargo->status !== 'pending') {
            return response()->json(['message' => 'Request is no longer pending'], 422);
        }

        $validated = $request->validate([
            'quoted_price' => 'required|numeric|min:1',
        ]);

        $cargo->update([
            'quoted_price' => $validated['quoted_price'],
            'status' => 'quoted',
        ]);

        $cargo->load(['customer', 'vehicle']);

        $this->notify->customerDriverQuoted(
            customerName: $cargo->customer->name,
            customerEmail: $cargo->customer->email,
            customerPhone: $cargo->customer->phone,
            driverName: $request->user()->name,
            pickupAddress: $cargo->pickup_address,
            destAddress: $cargo->dest_address,
            distanceKm: (float) $cargo->distance_km,
            quotedPrice: (float) $cargo->quoted_price,
            customerWhatsapp: $cargo->customer->whatsapp_number,
        );

        return response()->json(['data' => $cargo]);
    }

    // POST /cargo/requests/{cargo}/start — driver starts the trip
    public function startTrip(Request $request, CargoRequest $cargo): JsonResponse
    {
        $driver = $request->user()->driver;
        if (! $driver || $cargo->driver_id !== $driver->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($cargo->status !== 'accepted') {
            return response()->json(['message' => 'Request not accepted yet'], 422);
        }
        if (! $cargo->isPaid()) {
            return response()->json(['message' => 'Customer must complete payment before the trip can start'], 422);
        }

        $cargo->update(['status' => 'in_progress']);
        $cargo->load(['customer']);

        $this->notify->customerTripStarted(
            customerName: $cargo->customer->name,
            customerEmail: $cargo->customer->email,
            customerPhone: $cargo->customer->phone,
            driverName: $request->user()->name,
            pickupAddress: $cargo->pickup_address,
            destAddress: $cargo->dest_address,
            customerWhatsapp: $cargo->customer->whatsapp_number,
        );

        return response()->json(['data' => $cargo]);
    }

    // POST /cargo/requests/{cargo}/deliver — driver marks as delivered
    public function markDelivered(Request $request, CargoRequest $cargo): JsonResponse
    {
        $driver = $request->user()->driver;
        if (! $driver || $cargo->driver_id !== $driver->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($cargo->status !== 'in_progress') {
            return response()->json(['message' => 'Trip not in progress'], 422);
        }

        $cargo->update(['status' => 'delivered']);
        $cargo->load(['customer']);

        $this->notify->customerCargoDelivered(
            customerName: $cargo->customer->name,
            customerEmail: $cargo->customer->email,
            customerPhone: $cargo->customer->phone,
            driverName: $request->user()->name,
            destAddress: $cargo->dest_address,
            quotedPrice: (float) $cargo->quoted_price,
            customerWhatsapp: $cargo->customer->whatsapp_number,
        );

        return response()->json(['data' => $cargo]);
    }

    // POST /driver/location — driver updates their location & availability
    public function updateLocation(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;
        if (! $driver) {
            return response()->json(['message' => 'Not a driver'], 403);
        }

        $validated = $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'is_available' => 'required|boolean',
        ]);

        $location = DriverLocation::updateOrCreate(
            ['driver_id' => $driver->id],
            $validated
        );

        return response()->json(['data' => $location]);
    }
}
