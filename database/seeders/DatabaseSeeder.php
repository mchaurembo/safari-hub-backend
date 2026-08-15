<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\Role;
use App\Models\Route;
use App\Models\TransportOwner;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $adminRole = Role::where('name', 'admin')->first();
        $ownerRole = Role::where('name', 'owner')->first();
        $driverRole = Role::where('name', 'driver')->first();
        $customerRole = Role::where('name', 'customer')->first();

        User::firstOrCreate(
            ['email' => 'admin@safarihub360.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role_id' => $adminRole->id,
                'status' => 'active',
            ]
        );

        $owner = User::firstOrCreate(
            ['email' => 'owner@safarihub360.com'],
            [
                'name' => 'Transport Owner',
                'password' => Hash::make('password'),
                'role_id' => $ownerRole->id,
                'status' => 'active',
            ]
        );

        $transportOwner = TransportOwner::firstOrCreate(
            ['user_id' => $owner->id],
            [
                'company_name' => 'Express Transport Co',
                'license_number' => 'LIC-001',
                'address' => 'Dar es Salaam',
                'status' => 'approved',
            ]
        );

        $driverUser = User::firstOrCreate(
            ['email' => 'driver@safarihub360.com'],
            [
                'name' => 'Driver User',
                'password' => Hash::make('password'),
                'role_id' => $driverRole->id,
                'status' => 'active',
            ]
        );

        Driver::firstOrCreate(
            ['user_id' => $driverUser->id],
            [
                'owner_id' => $transportOwner->id,
                'license_number' => 'DL-001',
                'experience_years' => 5,
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'customer@safarihub360.com'],
            [
                'name' => 'Customer User',
                'password' => Hash::make('password'),
                'role_id' => $customerRole->id,
                'status' => 'active',
            ]
        );

        $routeDarDodoma = Route::firstOrCreate(
            ['origin' => 'Dar es Salaam', 'destination' => 'Dodoma'],
            ['distance' => 450, 'estimated_time' => 360]
        );

        Route::firstOrCreate(
            ['origin' => 'Dar es Salaam', 'destination' => 'Arusha'],
            ['distance' => 560, 'estimated_time' => 420]
        );

        $vehicle = Vehicle::firstOrCreate(
            ['vehicle_number' => 'T 123 ABC', 'owner_id' => $transportOwner->id],
            [
                'vehicle_type' => 'bus',
                'total_seats' => 45,
                'model' => 'Scania',
                'status' => 'active',
            ]
        );

        $driver = Driver::where('user_id', $driverUser->id)->first();

        \App\Models\Trip::firstOrCreate(
            [
                'route_id' => $routeDarDodoma->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'departure_time' => now()->addDays(2)->setTime(8, 0, 0),
            ],
            [
                'arrival_time' => now()->addDays(2)->setTime(14, 0, 0),
                'price' => 25000,
                'available_seats' => 45,
                'status' => 'scheduled',
            ]
        );
    }
}
