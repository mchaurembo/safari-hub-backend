<?php

use Illuminate\Support\Facades\Route;

// Auth
Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
Route::post('/login',    [App\Http\Controllers\Api\AuthController::class, 'login']);

// Password reset via OTP — throttled: 10 requests per minute per IP
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/forgot-password', [App\Http\Controllers\Api\AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [App\Http\Controllers\Api\AuthController::class, 'resetPassword']);
});

// Debug (remove after fixing)
Route::get('/debug/owner-status/{email}', function (\Illuminate\Http\Request $request, $email) {
    $user = \App\Models\User::with(['role', 'transportOwner'])->where('email', $email)->first();
    if (!$user) return response()->json(['error' => 'User not found']);
    return response()->json([
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => $user->role?->name,
        'transport_owner' => $user->transportOwner,
    ]);
});

// Public
Route::get('/routes', [App\Http\Controllers\Api\RouteController::class, 'index']);
Route::get('/trips', [App\Http\Controllers\Api\TripController::class, 'index']);
Route::get('/trips/{trip}', [App\Http\Controllers\Api\TripController::class, 'show']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout',          [App\Http\Controllers\Api\AuthController::class, 'logout']);
    Route::get('/user',             [App\Http\Controllers\Api\AuthController::class, 'user']);
    Route::get('/me',               [App\Http\Controllers\Api\AuthController::class, 'me']);
    Route::put('/profile',          [App\Http\Controllers\Api\AuthController::class, 'updateProfile']);
    Route::put('/user/password',    [App\Http\Controllers\Api\AuthController::class, 'changePassword']);
    Route::post('/change-password', [App\Http\Controllers\Api\AuthController::class, 'changePassword']);

    // Role enrollment for multi-role dashboard
    Route::post('/roles/enroll',   [App\Http\Controllers\Api\AuthController::class, 'enrollRole']);

    // Customer
    Route::get('/my-bookings', [App\Http\Controllers\Api\BookingController::class, 'myBookings']);
    Route::post('/bookings', [App\Http\Controllers\Api\BookingController::class, 'store']);
    Route::post('/bookings/{booking}/cancel', [App\Http\Controllers\Api\BookingController::class, 'cancel']);

    // Owner
    Route::post('/vehicles/{vehicle}/assign-driver', [App\Http\Controllers\Api\VehicleController::class, 'assignDriver']);
    Route::delete('/vehicles/{vehicle}/unassign-driver/{driver}', [App\Http\Controllers\Api\VehicleController::class, 'unassignDriver']);
    Route::apiResource('/vehicles', App\Http\Controllers\Api\VehicleController::class)->except(['index', 'show']);
    Route::apiResource('/drivers', App\Http\Controllers\Api\DriverController::class)->except(['index', 'show']);
    Route::post('/trips', [App\Http\Controllers\Api\TripController::class, 'store']);
    Route::post('/owner/profile', [App\Http\Controllers\Api\OwnerController::class, 'saveProfile']);
    Route::get('/owner/vehicles', [App\Http\Controllers\Api\OwnerController::class, 'vehicles']);
    Route::get('/owner/drivers', [App\Http\Controllers\Api\OwnerController::class, 'drivers']);
    Route::get('/owner/trips', [App\Http\Controllers\Api\OwnerController::class, 'trips']);
    Route::get('/owner/revenue', [App\Http\Controllers\Api\OwnerController::class, 'revenue']);
    Route::get('/owner/cargo-trips', [App\Http\Controllers\Api\OwnerController::class, 'cargoTrips']);
    Route::get('/owner/earnings', [App\Http\Controllers\Api\OwnerController::class, 'earnings']);

    // Owner - search available drivers
    Route::get('/owner/available-drivers', [App\Http\Controllers\Api\OwnerController::class, 'availableDrivers']);

    // Cargo — public nearby drivers (customer uses before login too)
    Route::get('/cargo/nearby-drivers', [App\Http\Controllers\Api\CargoController::class, 'nearbyDrivers']);

    // Cargo — customer
    Route::post('/cargo/requests', [App\Http\Controllers\Api\CargoController::class, 'store']);
    Route::get('/cargo/my-requests', [App\Http\Controllers\Api\CargoController::class, 'myRequests']);
    Route::post('/cargo/requests/{cargo}/accept-quote', [App\Http\Controllers\Api\CargoController::class, 'acceptQuote']);
    Route::post('/cargo/requests/{cargo}/decline-quote', [App\Http\Controllers\Api\CargoController::class, 'declineQuote']);
    Route::post('/cargo/requests/{cargo}/cancel', [App\Http\Controllers\Api\CargoController::class, 'cancel']);
    Route::post('/cargo/requests/{cargo}/confirm-delivery', [App\Http\Controllers\Api\CargoController::class, 'confirmDelivery']);

    // Cargo — driver
    Route::get('/cargo/driver-requests', [App\Http\Controllers\Api\CargoController::class, 'driverRequests']);
    Route::post('/cargo/requests/{cargo}/quote', [App\Http\Controllers\Api\CargoController::class, 'quote']);
    Route::post('/cargo/requests/{cargo}/start', [App\Http\Controllers\Api\CargoController::class, 'startTrip']);
    Route::post('/cargo/requests/{cargo}/deliver', [App\Http\Controllers\Api\CargoController::class, 'markDelivered']);
    Route::post('/driver/location', [App\Http\Controllers\Api\CargoController::class, 'updateLocation']);

    // Driver
    Route::get('/driver/trips', [App\Http\Controllers\Api\DriverController::class, 'myTrips']);
    Route::post('/trips/{trip}/start', [App\Http\Controllers\Api\TripController::class, 'start']);
    Route::post('/trips/{trip}/end', [App\Http\Controllers\Api\TripController::class, 'end']);
    Route::get('/trips/{trip}/passengers', [App\Http\Controllers\Api\TripController::class, 'passengers']);

    // Driver Documents
    Route::get('/driver/documents', [App\Http\Controllers\Api\DocumentController::class, 'index']);
    Route::get('/driver/documents/{document}/file', [App\Http\Controllers\Api\DocumentController::class, 'file']);
    Route::post('/driver/documents', [App\Http\Controllers\Api\DocumentController::class, 'store']);
    Route::delete('/driver/documents/{document}', [App\Http\Controllers\Api\DocumentController::class, 'destroy']);

    // Jobs — Owner
    Route::get('/owner/documents/{document}/file', [App\Http\Controllers\Api\DocumentController::class, 'ownerFile']);
    Route::get('/owner/job-postings', [App\Http\Controllers\Api\JobController::class, 'ownerPostings']);
    Route::post('/owner/job-postings', [App\Http\Controllers\Api\JobController::class, 'createPosting']);
    Route::put('/owner/job-postings/{posting}', [App\Http\Controllers\Api\JobController::class, 'updatePosting']);
    Route::delete('/owner/job-postings/{posting}', [App\Http\Controllers\Api\JobController::class, 'deletePosting']);
    Route::get('/owner/job-postings/{posting}/applications', [App\Http\Controllers\Api\JobController::class, 'postingApplications']);
    Route::post('/owner/applications/{application}/review', [App\Http\Controllers\Api\JobController::class, 'reviewApplication']);

    // Jobs — Driver
    Route::get('/jobs', [App\Http\Controllers\Api\JobController::class, 'browsePostings']);
    Route::post('/jobs/{posting}/apply', [App\Http\Controllers\Api\JobController::class, 'applyToPosting']);
    Route::get('/driver/applications', [App\Http\Controllers\Api\JobController::class, 'myApplications']);
    Route::put('/driver/applications/{application}', [App\Http\Controllers\Api\JobController::class, 'updateApplication']);
    Route::delete('/driver/applications/{application}', [App\Http\Controllers\Api\JobController::class, 'withdrawApplication']);

    // Admin
    Route::get('/admin/users', [App\Http\Controllers\Api\AdminController::class, 'users']);
    Route::post('/admin/users', [App\Http\Controllers\Api\AdminController::class, 'createUser']);
    Route::put('/admin/users/{user}', [App\Http\Controllers\Api\AdminController::class, 'updateUser']);
    Route::post('/admin/users/{user}/status', [App\Http\Controllers\Api\AdminController::class, 'updateUserStatus']);
    Route::post('/admin/users/{user}/roles', [App\Http\Controllers\Api\AdminController::class, 'addUserRole']);
    Route::delete('/admin/users/{user}/roles/{role}', [App\Http\Controllers\Api\AdminController::class, 'removeUserRole']);
    Route::delete('/admin/users/{user}', [App\Http\Controllers\Api\AdminController::class, 'deleteUser']);
    Route::get('/admin/transport-owners', [App\Http\Controllers\Api\AdminController::class, 'transportOwners']);
    Route::get('/admin/garages', [App\Http\Controllers\Api\AdminController::class, 'garages']);
    Route::get('/admin/reports', [App\Http\Controllers\Api\AdminController::class, 'reports']);
    Route::post('/admin/approve-owner/{transportOwner}', [App\Http\Controllers\Api\AdminController::class, 'approveOwner']);
    Route::post('/admin/approve-owner-by-user/{user}', [App\Http\Controllers\Api\AdminController::class, 'approveOwnerByUser']);
    Route::get('/admin/complaints', [App\Http\Controllers\Api\AdminController::class, 'complaints']);
    Route::post('/admin/complaints/{complaint}/resolve', [App\Http\Controllers\Api\AdminController::class, 'resolveComplaint']);

    // Garage Module — Phase 1
    Route::get('/garage/ping', [App\Http\Controllers\Api\GarageController::class, 'ping']);
    Route::get('/garage/dashboard', [App\Http\Controllers\Api\GarageController::class, 'dashboard']);
    Route::get('/garage/profile', [App\Http\Controllers\Api\GarageController::class, 'showGarage']);
    Route::put('/garage/profile', [App\Http\Controllers\Api\GarageController::class, 'updateGarage']);

    Route::get('/garage/services', [App\Http\Controllers\Api\GarageController::class, 'services']);
    Route::post('/garage/services', [App\Http\Controllers\Api\GarageController::class, 'storeService']);
    Route::put('/garage/services/{service}', [App\Http\Controllers\Api\GarageController::class, 'updateService']);
    Route::delete('/garage/services/{service}', [App\Http\Controllers\Api\GarageController::class, 'destroyService']);

    Route::get('/garage/technicians', [App\Http\Controllers\Api\GarageController::class, 'technicians']);
    Route::post('/garage/technicians', [App\Http\Controllers\Api\GarageController::class, 'storeTechnician']);
    Route::put('/garage/technicians/{technician}', [App\Http\Controllers\Api\GarageController::class, 'updateTechnician']);

    Route::get('/garage/customers', [App\Http\Controllers\Api\GarageController::class, 'customers']);
    Route::put('/garage/customers/{customer}', [App\Http\Controllers\Api\GarageController::class, 'updateCustomer']);
    Route::get('/garage/bookings', [App\Http\Controllers\Api\GarageController::class, 'bookings']);
    Route::post('/garage/bookings', [App\Http\Controllers\Api\GarageController::class, 'storeBooking']);
    Route::put('/garage/bookings/{booking}', [App\Http\Controllers\Api\GarageController::class, 'updateBooking']);
});
