<?php

namespace App\Providers;

use App\Models\Garage;
use App\Models\GarageBooking;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Policies\GarageBookingPolicy;
use App\Policies\GaragePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\VehiclePolicy;
use App\Policies\WorkOrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(Garage::class, GaragePolicy::class);
        Gate::policy(GarageBooking::class, GarageBookingPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
    }
}
