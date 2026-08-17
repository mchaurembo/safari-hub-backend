<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CargoController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\GarageController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\OwnerController;
use App\Http\Controllers\Api\Payments\AdminPaymentController;
use App\Http\Controllers\Api\Payments\PaymentController;
use App\Http\Controllers\Api\Payments\PaymentWebhookController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\WorkOrderController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Password reset via OTP — throttled: 10 requests per minute per IP
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Debug (remove after fixing)
Route::get('/debug/owner-status/{email}', function (Request $request, $email) {
    $user = User::with(['role', 'transportOwner'])->where('email', $email)->first();
    if (! $user) {
        return response()->json(['error' => 'User not found']);
    }

    return response()->json([
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => $user->role?->name,
        'transport_owner' => $user->transportOwner,
    ]);
});

// Public
Route::get('/routes', [RouteController::class, 'index']);
Route::get('/trips', [TripController::class, 'index']);
Route::get('/trips/{trip}', [TripController::class, 'show']);

// Public payment webhooks (signature-verified per provider)
Route::post('/payments/webhooks/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:60,1');
Route::match(['get', 'post'], '/payments/stub-checkout/{paymentReference}', [PaymentWebhookController::class, 'stubComplete'])
    ->middleware('throttle:30,1');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Capability enrollment (canonical). /roles/* kept as aliases.
    Route::post('/capabilities/enroll', [AuthController::class, 'enrollRole']);
    Route::post('/roles/enroll', [AuthController::class, 'enrollRole']);
    Route::post('/capabilities/unenroll', [AuthController::class, 'unenrollRole']);
    Route::post('/roles/unenroll', [AuthController::class, 'unenrollRole']);

    // Customer
    Route::get('/my-bookings', [BookingController::class, 'myBookings']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);

    // Owner
    Route::post('/vehicles/{vehicle}/assign-driver', [VehicleController::class, 'assignDriver']);
    Route::delete('/vehicles/{vehicle}/unassign-driver/{driver}', [VehicleController::class, 'unassignDriver']);
    Route::apiResource('/vehicles', VehicleController::class)->except(['index', 'show']);
    Route::apiResource('/drivers', DriverController::class)->except(['index', 'show']);
    Route::post('/trips', [TripController::class, 'store']);
    Route::post('/routes', [RouteController::class, 'store']);
    Route::post('/owner/profile', [OwnerController::class, 'saveProfile']);
    Route::get('/owner/vehicles', [OwnerController::class, 'vehicles']);
    Route::get('/owner/drivers', [OwnerController::class, 'drivers']);
    Route::get('/owner/drivers/{driver}', [OwnerController::class, 'showDriver']);
    Route::get('/owner/trips', [OwnerController::class, 'trips']);
    Route::get('/owner/revenue', [OwnerController::class, 'revenue']);
    Route::get('/owner/cargo-trips', [OwnerController::class, 'cargoTrips']);
    Route::get('/owner/earnings', [OwnerController::class, 'earnings']);

    // Owner - search available drivers
    Route::get('/owner/available-drivers', [OwnerController::class, 'availableDrivers']);

    // Cargo — public nearby drivers (customer uses before login too)
    Route::get('/cargo/nearby-drivers', [CargoController::class, 'nearbyDrivers']);

    // Cargo — customer
    Route::post('/cargo/requests', [CargoController::class, 'store']);
    Route::get('/cargo/my-requests', [CargoController::class, 'myRequests']);
    Route::post('/cargo/requests/{cargo}/accept-quote', [CargoController::class, 'acceptQuote']);
    Route::post('/cargo/requests/{cargo}/decline-quote', [CargoController::class, 'declineQuote']);
    Route::post('/cargo/requests/{cargo}/cancel', [CargoController::class, 'cancel']);
    Route::post('/cargo/requests/{cargo}/confirm-delivery', [CargoController::class, 'confirmDelivery']);

    // Cargo — driver
    Route::get('/cargo/driver-requests', [CargoController::class, 'driverRequests']);
    Route::post('/cargo/requests/{cargo}/quote', [CargoController::class, 'quote']);
    Route::post('/cargo/requests/{cargo}/start', [CargoController::class, 'startTrip']);
    Route::post('/cargo/requests/{cargo}/deliver', [CargoController::class, 'markDelivered']);
    Route::post('/driver/location', [CargoController::class, 'updateLocation']);

    // Driver
    Route::get('/driver/trips', [DriverController::class, 'myTrips']);
    Route::post('/trips/{trip}/start', [TripController::class, 'start']);
    Route::post('/trips/{trip}/end', [TripController::class, 'end']);
    Route::get('/trips/{trip}/passengers', [TripController::class, 'passengers']);

    // Driver Documents
    Route::get('/driver/documents', [DocumentController::class, 'index']);
    Route::get('/driver/documents/{document}/file', [DocumentController::class, 'file']);
    Route::post('/driver/documents', [DocumentController::class, 'store']);
    Route::delete('/driver/documents/{document}', [DocumentController::class, 'destroy']);

    // Jobs — Owner
    Route::get('/owner/documents/{document}/file', [DocumentController::class, 'ownerFile']);
    Route::get('/owner/job-postings', [JobController::class, 'ownerPostings']);
    Route::post('/owner/job-postings', [JobController::class, 'createPosting']);
    Route::put('/owner/job-postings/{posting}', [JobController::class, 'updatePosting']);
    Route::delete('/owner/job-postings/{posting}', [JobController::class, 'deletePosting']);
    Route::get('/owner/job-postings/{posting}/applications', [JobController::class, 'postingApplications']);
    Route::post('/owner/applications/{application}/review', [JobController::class, 'reviewApplication']);

    // Jobs — Driver
    Route::get('/jobs', [JobController::class, 'browsePostings']);
    Route::post('/jobs/{posting}/apply', [JobController::class, 'applyToPosting']);
    Route::get('/driver/applications', [JobController::class, 'myApplications']);
    Route::put('/driver/applications/{application}', [JobController::class, 'updateApplication']);
    Route::delete('/driver/applications/{application}', [JobController::class, 'withdrawApplication']);

    // Admin
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::post('/admin/users', [AdminController::class, 'createUser']);
    Route::put('/admin/users/{user}', [AdminController::class, 'updateUser']);
    Route::post('/admin/users/{user}/status', [AdminController::class, 'updateUserStatus']);
    Route::post('/admin/users/{user}/roles', [AdminController::class, 'addUserRole']);
    Route::delete('/admin/users/{user}/roles/{role}', [AdminController::class, 'removeUserRole']);
    Route::delete('/admin/users/{user}', [AdminController::class, 'deleteUser']);
    Route::get('/admin/transport-owners', [AdminController::class, 'transportOwners']);
    Route::get('/admin/garages', [AdminController::class, 'garages']);
    Route::get('/admin/reports', [AdminController::class, 'reports']);
    Route::post('/admin/approve-owner/{transportOwner}', [AdminController::class, 'approveOwner']);
    Route::post('/admin/approve-owner-by-user/{user}', [AdminController::class, 'approveOwnerByUser']);
    Route::get('/admin/complaints', [AdminController::class, 'complaints']);
    Route::post('/admin/complaints/{complaint}/resolve', [AdminController::class, 'resolveComplaint']);

    // Payments — customer
    Route::get('/payments/methods', [PaymentController::class, 'methods']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/payments/{payment}/retry', [PaymentController::class, 'retry']);
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt']);

    // Payments — admin
    Route::get('/admin/payments/dashboard', [AdminPaymentController::class, 'dashboard']);
    Route::post('/admin/payments/expire-stale', [AdminPaymentController::class, 'expireStale']);
    Route::get('/admin/payments', [AdminPaymentController::class, 'index']);
    Route::get('/admin/payments/{payment}', [AdminPaymentController::class, 'show']);
    Route::get('/admin/refunds', [AdminPaymentController::class, 'refunds']);
    Route::post('/admin/payments/{payment}/refunds', [AdminPaymentController::class, 'requestRefund']);
    Route::get('/admin/payouts', [AdminPaymentController::class, 'payouts']);
    Route::post('/admin/payouts', [AdminPaymentController::class, 'createPayout']);
    Route::post('/admin/payouts/{payout}/process', [AdminPaymentController::class, 'processPayout']);

    // Garage Module — Phase 1
    Route::get('/garage/ping', [GarageController::class, 'ping']);
    Route::get('/garage/directory', [GarageController::class, 'directory']);
    Route::post('/garage', [GarageController::class, 'createGarage']);
    Route::post('/garage/join', [GarageController::class, 'joinAsTechnician']);
    Route::get('/garage/dashboard', [GarageController::class, 'dashboard']);
    Route::get('/garage/profile', [GarageController::class, 'showGarage']);
    Route::put('/garage/profile', [GarageController::class, 'updateGarage']);

    Route::get('/garage/services', [GarageController::class, 'services']);
    Route::post('/garage/services', [GarageController::class, 'storeService']);
    Route::put('/garage/services/{service}', [GarageController::class, 'updateService']);
    Route::delete('/garage/services/{service}', [GarageController::class, 'destroyService']);

    Route::get('/garage/technicians', [GarageController::class, 'technicians']);
    Route::post('/garage/technicians', [GarageController::class, 'storeTechnician']);
    Route::put('/garage/technicians/{technician}', [GarageController::class, 'updateTechnician']);

    Route::get('/garage/customers', [GarageController::class, 'customers']);
    Route::put('/garage/customers/{customer}', [GarageController::class, 'updateCustomer']);
    Route::get('/garage/bookings', [GarageController::class, 'bookings']);
    Route::post('/garage/bookings', [GarageController::class, 'storeBooking']);
    Route::put('/garage/bookings/{booking}', [GarageController::class, 'updateBooking']);

    Route::get('/garage/work-orders', [WorkOrderController::class, 'index']);
    Route::get('/garage/work-orders/{workOrder}', [WorkOrderController::class, 'show']);
    Route::post('/garage/work-orders/{workOrder}/start', [WorkOrderController::class, 'start']);
    Route::post('/garage/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete']);
    Route::post('/garage/work-orders/{workOrder}/items', [WorkOrderController::class, 'addItem']);
    Route::get('/service-history', [WorkOrderController::class, 'serviceHistory']);
});
