<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        if ((int) $workOrder->customer_id === (int) $user->id) {
            return true;
        }

        $garage = $workOrder->garage;
        if (! $garage) {
            return false;
        }

        if ($user->ownsGarage($garage)) {
            return $user->hasPermission('work_order.view') || $user->hasGarageStaffCapability();
        }

        if ($user->isGarageTechnician($garage)) {
            $tech = $user->technicians()->where('garage_id', $garage->id)->first();

            return $tech && (int) $workOrder->technician_id === (int) $tech->id;
        }

        return false;
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        $garage = $workOrder->garage;
        if (! $garage) {
            return false;
        }

        if ($user->ownsGarage($garage)) {
            return $user->hasPermission('work_order.update') || $user->hasGarageStaffCapability();
        }

        if ($user->isGarageTechnician($garage)) {
            $tech = $user->technicians()->where('garage_id', $garage->id)->first();

            return $tech
                && (int) $workOrder->technician_id === (int) $tech->id
                && ($user->hasPermission('work_order.update') || $user->hasCapability('technician'));
        }

        return false;
    }

    public function complete(User $user, WorkOrder $workOrder): bool
    {
        return $this->update($user, $workOrder)
            && ($user->hasPermission('work_order.complete')
                || $user->hasGarageStaffCapability()
                || $user->hasCapability('technician')
                || $user->hasCapability('admin'));
    }
}
