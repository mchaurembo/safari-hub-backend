<?php

namespace Database\Seeders;

use App\Models\BusinessCapability;
use App\Models\BusinessCategory;
use App\Models\BusinessType;
use App\Models\MembershipRole;
use App\Models\Permission;
use App\Models\Position;
use Illuminate\Database\Seeder;

class BusinessPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCapabilities();
        $this->seedCategoriesAndTypes();
        $this->seedMembershipRoles();
        $this->seedBusinessPermissions();
        $this->seedPositions();
    }

    private function seedCapabilities(): void
    {
        $capabilities = [
            ['code' => 'branch_management', 'name' => 'Branch Management', 'module_key' => 'branches'],
            ['code' => 'vehicle_management', 'name' => 'Vehicle Management', 'module_key' => 'vehicles'],
            ['code' => 'driver_management', 'name' => 'Driver Management', 'module_key' => 'drivers'],
            ['code' => 'fleet_management', 'name' => 'Fleet Management', 'module_key' => 'fleet'],
            ['code' => 'service_management', 'name' => 'Service Management', 'module_key' => 'services'],
            ['code' => 'product_management', 'name' => 'Product Management', 'module_key' => 'products'],
            ['code' => 'inventory_management', 'name' => 'Inventory Management', 'module_key' => 'inventory'],
            ['code' => 'booking_management', 'name' => 'Booking Management', 'module_key' => 'bookings'],
            ['code' => 'order_management', 'name' => 'Order Management', 'module_key' => 'orders'],
            ['code' => 'work_order_management', 'name' => 'Work Order Management', 'module_key' => 'work_orders'],
            ['code' => 'customer_management', 'name' => 'Customer Management', 'module_key' => 'customers'],
            ['code' => 'payment_management', 'name' => 'Payment Management', 'module_key' => 'payments'],
            ['code' => 'online_payment', 'name' => 'Online Payment', 'module_key' => 'online_payments'],
            ['code' => 'reporting', 'name' => 'Reports & Analytics', 'module_key' => 'reports'],
        ];

        foreach ($capabilities as $cap) {
            BusinessCapability::firstOrCreate(
                ['code' => $cap['code']],
                ['name' => $cap['name'], 'module_key' => $cap['module_key'], 'is_active' => true]
            );
        }
    }

    private function seedCategoriesAndTypes(): void
    {
        $catalog = [
            'transportation' => [
                'name' => 'Transportation',
                'types' => [
                    'passenger_transport' => [
                        'name' => 'Passenger Transport',
                        'capabilities' => ['vehicle_management', 'driver_management', 'fleet_management', 'booking_management', 'customer_management', 'payment_management', 'reporting'],
                    ],
                    'logistics' => [
                        'name' => 'Logistics & Delivery',
                        'capabilities' => ['vehicle_management', 'driver_management', 'fleet_management', 'booking_management', 'customer_management', 'payment_management', 'reporting'],
                    ],
                ],
            ],
            'automotive' => [
                'name' => 'Automotive',
                'types' => [
                    'garage' => [
                        'name' => 'Garage',
                        'capabilities' => ['vehicle_management', 'service_management', 'work_order_management', 'inventory_management', 'product_management', 'booking_management', 'customer_management', 'payment_management', 'reporting'],
                    ],
                    'car_wash' => [
                        'name' => 'Car Wash',
                        'capabilities' => ['service_management', 'booking_management', 'customer_management', 'payment_management'],
                    ],
                    'spare_parts' => [
                        'name' => 'Spare Parts',
                        'capabilities' => ['product_management', 'inventory_management', 'order_management', 'customer_management', 'payment_management', 'reporting'],
                    ],
                ],
            ],
            'retail_commerce' => [
                'name' => 'Retail & Commerce',
                'types' => [
                    'hardware' => [
                        'name' => 'Hardware',
                        'capabilities' => ['product_management', 'inventory_management', 'order_management', 'customer_management', 'payment_management', 'reporting'],
                    ],
                    'general_shop' => [
                        'name' => 'General Shop',
                        'capabilities' => ['product_management', 'inventory_management', 'order_management', 'customer_management', 'payment_management'],
                    ],
                ],
            ],
            'hospitality' => [
                'name' => 'Hospitality',
                'types' => [
                    'hotel' => [
                        'name' => 'Hotel',
                        'capabilities' => ['booking_management', 'service_management', 'product_management', 'customer_management', 'payment_management', 'reporting'],
                    ],
                    'restaurant' => [
                        'name' => 'Restaurant',
                        'capabilities' => ['service_management', 'product_management', 'order_management', 'booking_management', 'customer_management', 'payment_management'],
                    ],
                ],
            ],
        ];

        $sort = 0;
        foreach ($catalog as $code => $category) {
            $categoryModel = BusinessCategory::firstOrCreate(
                ['code' => $code],
                ['name' => $category['name'], 'sort_order' => $sort++, 'is_active' => true]
            );

            foreach ($category['types'] as $typeCode => $type) {
                BusinessType::firstOrCreate(
                    ['business_category_id' => $categoryModel->id, 'code' => $typeCode],
                    [
                        'name' => $type['name'],
                        'default_capability_codes' => $type['capabilities'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function seedMembershipRoles(): void
    {
        $roles = [
            ['scope' => MembershipRole::SCOPE_PLATFORM, 'code' => MembershipRole::CODE_PLATFORM_ADMIN, 'name' => 'Platform Administrator'],
            ['scope' => MembershipRole::SCOPE_BUSINESS, 'code' => MembershipRole::CODE_OWNER, 'name' => 'Owner'],
            ['scope' => MembershipRole::SCOPE_BUSINESS, 'code' => MembershipRole::CODE_MANAGER, 'name' => 'Manager'],
            ['scope' => MembershipRole::SCOPE_BUSINESS, 'code' => MembershipRole::CODE_STAFF, 'name' => 'Staff'],
        ];

        foreach ($roles as $role) {
            MembershipRole::firstOrCreate(
                ['scope' => $role['scope'], 'code' => $role['code']],
                ['name' => $role['name'], 'is_system' => true]
            );
        }
    }

    private function seedBusinessPermissions(): void
    {
        $businessPermissions = [
            ['code' => 'business.view', 'name' => 'View business', 'module' => 'business'],
            ['code' => 'business.update', 'name' => 'Update business', 'module' => 'business'],
            ['code' => 'business.members.view', 'name' => 'View members', 'module' => 'business'],
            ['code' => 'business.members.create', 'name' => 'Add members', 'module' => 'business'],
            ['code' => 'business.members.update', 'name' => 'Update members', 'module' => 'business'],
            ['code' => 'branch.view', 'name' => 'View branches', 'module' => 'business'],
            ['code' => 'branch.create', 'name' => 'Create branches', 'module' => 'business'],
            ['code' => 'branch.update', 'name' => 'Update branches', 'module' => 'business'],
            ['code' => 'customer.view', 'name' => 'View customers', 'module' => 'business'],
            ['code' => 'customer.create', 'name' => 'Create customers', 'module' => 'business'],
            ['code' => 'product.view', 'name' => 'View products', 'module' => 'business'],
            ['code' => 'product.create', 'name' => 'Create products', 'module' => 'business'],
            ['code' => 'product.update', 'name' => 'Update products', 'module' => 'business'],
            ['code' => 'order.view', 'name' => 'View orders', 'module' => 'business'],
            ['code' => 'order.create', 'name' => 'Create orders', 'module' => 'business'],
            ['code' => 'inventory.view', 'name' => 'View inventory', 'module' => 'business'],
            ['code' => 'inventory.adjust', 'name' => 'Adjust inventory', 'module' => 'business'],
            ['code' => 'payment.view', 'name' => 'View payments', 'module' => 'business'],
            ['code' => 'payment.refund', 'name' => 'Refund payments', 'module' => 'business'],
        ];

        foreach ($businessPermissions as $def) {
            Permission::firstOrCreate(
                ['code' => $def['code']],
                ['name' => $def['name'], 'module' => $def['module']]
            );
        }

        $allCodes = Permission::pluck('code')->all();
        $managerCodes = array_merge($allCodes, []);
        $managerCodes = array_values(array_filter($allCodes, fn ($c) => ! str_starts_with($c, 'admin.')));

        $staffCodes = [
            'business.view', 'customer.view', 'product.view', 'order.view', 'order.create',
            'booking.view', 'garage_booking.view', 'work_order.view', 'trip.view', 'vehicle.view',
        ];

        $ownerRole = MembershipRole::where('code', MembershipRole::CODE_OWNER)->first();
        $managerRole = MembershipRole::where('code', MembershipRole::CODE_MANAGER)->first();
        $staffRole = MembershipRole::where('code', MembershipRole::CODE_STAFF)->first();

        if ($ownerRole) {
            $ownerRole->permissions()->syncWithoutDetaching(
                Permission::whereIn('code', $managerCodes)->pluck('id')
            );
        }
        if ($managerRole) {
            $managerRole->permissions()->syncWithoutDetaching(
                Permission::whereIn('code', $managerCodes)->pluck('id')
            );
        }
        if ($staffRole) {
            $staffRole->permissions()->syncWithoutDetaching(
                Permission::whereIn('code', $staffCodes)->pluck('id')
            );
        }
    }

    private function seedPositions(): void
    {
        $transportType = BusinessType::where('code', 'passenger_transport')->first();
        $garageType = BusinessType::where('code', 'garage')->first();
        $hardwareType = BusinessType::where('code', 'hardware')->first();

        $positions = [
            [$transportType?->id, 'driver', 'Driver'],
            [$transportType?->id, 'fleet_officer', 'Fleet Officer'],
            [$transportType?->id, 'dispatcher', 'Dispatcher'],
            [$garageType?->id, 'technician', 'Technician'],
            [$garageType?->id, 'mechanic', 'Mechanic'],
            [$garageType?->id, 'service_advisor', 'Service Advisor'],
            [$hardwareType?->id, 'seller', 'Seller'],
            [$hardwareType?->id, 'cashier', 'Cashier'],
            [$hardwareType?->id, 'storekeeper', 'Storekeeper'],
        ];

        foreach ($positions as [$typeId, $code, $name]) {
            if (! $typeId) {
                continue;
            }
            Position::firstOrCreate(
                ['business_type_id' => $typeId, 'code' => $code, 'business_id' => null],
                ['name' => $name, 'is_active' => true]
            );
        }

        $this->assignPositionPermissions();
    }

    private function assignPositionPermissions(): void
    {
        $map = [
            'driver' => ['trip.view', 'trip.update_status', 'vehicle.view', 'booking.view'],
            'technician' => ['work_order.view', 'work_order.update', 'work_order.complete', 'garage_booking.view'],
            'seller' => ['product.view', 'order.view', 'order.create', 'customer.view'],
            'cashier' => ['product.view', 'order.view', 'order.create', 'payment.view'],
        ];

        foreach ($map as $code => $permissions) {
            $position = Position::where('code', $code)->whereNull('business_id')->first();
            if (! $position) {
                continue;
            }
            $position->permissions()->syncWithoutDetaching(
                Permission::whereIn('code', $permissions)->pluck('id')
            );
        }
    }
}
