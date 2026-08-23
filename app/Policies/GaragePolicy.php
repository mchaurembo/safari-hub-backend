<?php

namespace App\Policies;

use App\Models\Garage;
use App\Models\User;

class GaragePolicy
{
    public function view(User $user, Garage $garage): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        if ($user->ownsGarage($garage)) {
            return $user->hasPermission('garage.view') || $user->hasGarageStaffCapability();
        }

        if ($user->isGarageTechnician($garage)) {
            return $user->hasPermission('garage.view') || $user->hasCapability('technician');
        }

        // Customers may view active garages for booking.
        return $garage->status === 'active' && $user->hasPermission('garage_booking.create');
    }

    public function create(User $user): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        if ($user->hasPermission('garage.create') && $user->hasCapability('garage_owner')) {
            return true;
        }

        return $user->activeBusinessMemberships()
            ->whereHas('role', fn ($q) => $q->where('code', 'owner'))
            ->whereHas('business.type', fn ($q) => $q->whereIn('code', ['garage', 'car_wash']))
            ->exists();
    }

    public function update(User $user, Garage $garage): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        return $user->ownsGarage($garage)
            && ($user->hasPermission('garage.update') || $user->hasGarageStaffCapability());
    }

    public function manageServices(User $user, Garage $garage): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        return $user->ownsGarage($garage)
            && ($user->hasPermission('garage.manage_services') || $user->hasGarageStaffCapability());
    }
}
