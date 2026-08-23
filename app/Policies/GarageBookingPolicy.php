<?php

namespace App\Policies;

use App\Models\GarageBooking;
use App\Models\User;

class GarageBookingPolicy
{
    public function view(User $user, GarageBooking $booking): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        if ((int) $booking->customer_id === (int) $user->id) {
            return true;
        }

        $garage = $booking->garage;
        if (! $garage) {
            return false;
        }

        if ($user->ownsGarage($garage)) {
            return $user->hasPermission('garage_booking.view') || $user->hasGarageStaffCapability();
        }

        if ($user->isGarageTechnician($garage)) {
            $tech = $user->technicians()->where('garage_id', $garage->id)->first();

            return $tech && (int) $booking->technician_id === (int) $tech->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasCapability('admin')
            || $user->hasPermission('garage_booking.create')
            || $user->hasCapability('customer');
    }

    public function manage(User $user, GarageBooking $booking): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        $garage = $booking->garage;
        if (! $garage) {
            return false;
        }

        if ($user->ownsGarage($garage)) {
            return $user->hasPermission('garage_booking.manage') || $user->hasGarageStaffCapability();
        }

        if ($user->isGarageTechnician($garage)) {
            $tech = $user->technicians()->where('garage_id', $garage->id)->first();

            return $tech
                && (int) $booking->technician_id === (int) $tech->id
                && ($user->hasPermission('garage_booking.manage') || $user->hasCapability('technician'));
        }

        return false;
    }
}
