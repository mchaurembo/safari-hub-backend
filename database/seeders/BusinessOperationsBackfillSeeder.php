<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Driver;
use App\Models\Garage;
use App\Models\GarageBooking;
use App\Models\GarageService;
use App\Models\Technician;
use App\Models\TransportOwner;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\BusinessOperationService;
use Illuminate\Database\Seeder;

class BusinessOperationsBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $mapper = app(BusinessOperationService::class);

        foreach (Business::all() as $business) {
            if ($business->legacy_transport_owner_id) {
                TransportOwner::where('id', $business->legacy_transport_owner_id)
                    ->update(['business_id' => $business->id]);
                $mapper->syncFleetOperations($business->legacy_transport_owner_id, $business->id);
            }

            if ($business->legacy_garage_id) {
                Garage::where('id', $business->legacy_garage_id)
                    ->update(['business_id' => $business->id]);
                $mapper->syncGarageOperations($business->legacy_garage_id, $business->id);
            }
        }

        // Orphan legacy rows without business link yet
        foreach (TransportOwner::whereNull('business_id')->get() as $fleet) {
            $mapper->ensureBusinessForFleet($fleet);
        }

        foreach (Garage::whereNull('business_id')->get() as $garage) {
            $mapper->ensureBusinessForGarage($garage);
        }

        $this->command?->info('Business operations backfill complete.');
        $this->command?->info('Vehicles: '.Vehicle::whereNotNull('business_id')->count());
        $this->command?->info('Garage bookings: '.GarageBooking::whereNotNull('business_id')->count());
        $this->command?->info('Work orders: '.WorkOrder::whereNotNull('business_id')->count());
    }
}
