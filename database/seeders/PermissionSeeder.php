<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            // Transport / fleet
            ['code' => 'vehicle.view', 'name' => 'View vehicles', 'module' => 'transport'],
            ['code' => 'vehicle.create', 'name' => 'Create vehicles', 'module' => 'transport'],
            ['code' => 'vehicle.update', 'name' => 'Update vehicles', 'module' => 'transport'],
            ['code' => 'vehicle.delete', 'name' => 'Delete vehicles', 'module' => 'transport'],
            ['code' => 'vehicle.assign_driver', 'name' => 'Assign drivers to vehicles', 'module' => 'transport'],
            ['code' => 'driver.view', 'name' => 'View drivers', 'module' => 'transport'],
            ['code' => 'driver.invite', 'name' => 'Invite / hire drivers', 'module' => 'transport'],
            ['code' => 'driver.assign_vehicle', 'name' => 'Assign vehicles to drivers', 'module' => 'transport'],
            ['code' => 'driver.remove', 'name' => 'Remove drivers from fleet', 'module' => 'transport'],
            ['code' => 'booking.create', 'name' => 'Create transport bookings', 'module' => 'transport'],
            ['code' => 'booking.view', 'name' => 'View transport bookings', 'module' => 'transport'],
            ['code' => 'booking.cancel', 'name' => 'Cancel transport bookings', 'module' => 'transport'],
            ['code' => 'trip.view', 'name' => 'View trips', 'module' => 'transport'],
            ['code' => 'trip.manage', 'name' => 'Manage trips', 'module' => 'transport'],
            ['code' => 'trip.update_status', 'name' => 'Update trip status', 'module' => 'transport'],
            ['code' => 'report.view', 'name' => 'View reports', 'module' => 'transport'],
            ['code' => 'financial_report.view', 'name' => 'View financial reports', 'module' => 'transport'],

            // Garage
            ['code' => 'garage.view', 'name' => 'View garage', 'module' => 'garage'],
            ['code' => 'garage.create', 'name' => 'Create garage', 'module' => 'garage'],
            ['code' => 'garage.update', 'name' => 'Update garage', 'module' => 'garage'],
            ['code' => 'garage.manage_services', 'name' => 'Manage garage services', 'module' => 'garage'],
            ['code' => 'technician.view', 'name' => 'View technicians', 'module' => 'garage'],
            ['code' => 'technician.assign', 'name' => 'Assign technicians', 'module' => 'garage'],
            ['code' => 'technician.remove', 'name' => 'Remove technicians', 'module' => 'garage'],
            ['code' => 'garage_booking.create', 'name' => 'Create garage bookings', 'module' => 'garage'],
            ['code' => 'garage_booking.view', 'name' => 'View garage bookings', 'module' => 'garage'],
            ['code' => 'garage_booking.manage', 'name' => 'Manage garage bookings', 'module' => 'garage'],
            ['code' => 'work_order.view', 'name' => 'View work orders', 'module' => 'garage'],
            ['code' => 'work_order.update', 'name' => 'Update work orders', 'module' => 'garage'],
            ['code' => 'work_order.complete', 'name' => 'Complete work orders', 'module' => 'garage'],

            // Admin
            ['code' => 'admin.users', 'name' => 'Manage users', 'module' => 'admin'],
            ['code' => 'admin.approve', 'name' => 'Approve platform entities', 'module' => 'admin'],
            ['code' => 'admin.reports', 'name' => 'View admin reports', 'module' => 'admin'],
        ];

        foreach ($definitions as $def) {
            Permission::firstOrCreate(
                ['code' => $def['code']],
                ['name' => $def['name'], 'module' => $def['module']]
            );
        }

        $matrix = [
            'customer' => [
                'booking.create', 'booking.view', 'booking.cancel',
                'garage_booking.create', 'garage_booking.view',
                'trip.view',
            ],
            'owner' => [
                'vehicle.view', 'vehicle.create', 'vehicle.update', 'vehicle.delete', 'vehicle.assign_driver',
                'driver.view', 'driver.invite', 'driver.assign_vehicle', 'driver.remove',
                'booking.view', 'trip.view', 'trip.manage',
                'report.view', 'financial_report.view',
                'garage_booking.create', 'garage_booking.view',
            ],
            'driver' => [
                'trip.view', 'trip.update_status',
                'vehicle.view',
                'booking.view',
                'garage_booking.create', 'garage_booking.view',
            ],
            'garage_owner' => [
                'garage.view', 'garage.create', 'garage.update', 'garage.manage_services',
                'technician.view', 'technician.assign', 'technician.remove',
                'garage_booking.view', 'garage_booking.manage',
                'work_order.view', 'work_order.update', 'work_order.complete',
                'report.view', 'financial_report.view',
                'booking.create', 'booking.view',
            ],
            'technician' => [
                'garage.view',
                'garage_booking.view', 'garage_booking.manage',
                'work_order.view', 'work_order.update', 'work_order.complete',
                'technician.view',
            ],
            'admin' => Permission::query()->pluck('code')->all(),
        ];

        foreach ($matrix as $roleName => $codes) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $permissionIds = Permission::whereIn('code', $codes)->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}
