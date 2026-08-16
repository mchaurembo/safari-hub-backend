<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function view(User $user, Vehicle $vehicle): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        if ($user->hasPermission('vehicle.view') && $user->ownsFleetVehicle($vehicle)) {
            return true;
        }

        // Assigned driver may view the vehicle.
        $driver = $user->driver;
        if ($driver && $vehicle->drivers()->where('drivers.id', $driver->id)->exists()) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        $fleet = $user->transportOwner;

        return $user->hasPermission('vehicle.create')
            && $fleet
            && $fleet->status === 'approved';
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        return $user->hasPermission('vehicle.update')
            && $user->ownsFleetVehicle($vehicle);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        return $user->hasPermission('vehicle.delete')
            && $user->ownsFleetVehicle($vehicle);
    }

    public function assignDriver(User $user, Vehicle $vehicle): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        return $user->hasPermission('vehicle.assign_driver')
            && $user->ownsFleetVehicle($vehicle);
    }
}
