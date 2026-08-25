<?php

namespace App\Services;

use App\Support\BusinessContext;

class BusinessNavigationService
{
    /**
     * @return list<array{key: string, label: string, group: string, route: string, route_mobile: string, icon: string}>
     */
    public function modules(BusinessContext $context): array
    {
        $business = $context->business;
        $permissions = $context->permissions;

        $has = fn (string $perm): bool => in_array($perm, $permissions, true);
        $hasAny = fn (array $perms): bool => array_intersect($perms, $permissions) !== [];
        $cap = fn (string $code): bool => $business->hasCapability($code);

        $modules = [];

        foreach ($this->definitions($business) as $def) {
            if ($def['capability'] && ! $cap($def['capability'])) {
                continue;
            }
            if (! empty($def['permissions_any']) && ! $hasAny($def['permissions_any'])) {
                continue;
            }
            if ($def['permission'] && ! $has($def['permission'])) {
                continue;
            }

            $route = $def['route_resolver']($business, $context);
            if (! $route) {
                continue;
            }

            $modules[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'group' => $def['group'],
                'route' => $route['web'],
                'route_mobile' => $route['mobile'],
                'icon' => $def['icon'],
            ];
        }

        return $this->consolidateWorkshopSidebar($modules, $business);
    }

    /**
     * Garage / car wash: bookings, customers, and inventory live inside Manage garage.
     * Hide duplicate sidebar rows and customer-payments until owner finance UI exists.
     *
     * @param  list<array<string, mixed>>  $modules
     * @return list<array<string, mixed>>
     */
    private function consolidateWorkshopSidebar(array $modules, $business): array
    {
        $business->loadMissing('type');
        $type = $business->type?->code;
        if (! in_array($type, ['garage', 'car_wash'], true)) {
            return $modules;
        }

        $hideKeys = ['bookings', 'customers', 'inventory', 'payments'];

        return array_values(array_filter(
            $modules,
            fn (array $m) => ! in_array($m['key'] ?? '', $hideKeys, true),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions($business): array
    {
        $bizId = $business->id;

        return [
            [
                'key' => 'fleet',
                'label' => 'Fleet Management',
                'group' => 'operations',
                'icon' => 'fleet',
                'capability' => 'fleet_management',
                'permission' => 'vehicle.view',
                'route_resolver' => fn ($b) => $b->legacy_transport_owner_id
                    ? ['web' => '/owner', 'mobile' => 'OwnerTabs']
                    : null,
            ],
            [
                'key' => 'garage_ops',
                'label' => 'Manage garage',
                'group' => 'operations',
                'icon' => 'garage',
                'capability' => 'work_order_management',
                'permission' => 'work_order.view',
                'route_resolver' => fn ($b) => $b->legacy_garage_id
                    ? ['web' => '/garage', 'mobile' => 'GarageTabs']
                    : null,
            ],
            [
                'key' => 'bookings',
                'label' => 'Bookings',
                'group' => 'operations',
                'icon' => 'calendar',
                'capability' => 'booking_management',
                'permission' => null,
                'permissions_any' => ['booking.view', 'garage_booking.view'],
                'route_resolver' => fn ($b) => $b->legacy_garage_id
                    ? ['web' => '/garage?tab=bookings', 'mobile' => 'GarageTabs']
                    : ($b->legacy_transport_owner_id
                        ? ['web' => '/owner?tab=passenger-trips', 'mobile' => 'OwnerTabs']
                        : ['web' => "/business/{$bizId}", 'mobile' => 'BusinessOverview']),
            ],
            [
                'key' => 'customers',
                'label' => 'Customers',
                'group' => 'operations',
                'icon' => 'users',
                'capability' => 'customer_management',
                'permission' => 'customer.view',
                'route_resolver' => fn ($b) => $b->legacy_garage_id
                    ? ['web' => '/garage?tab=customers', 'mobile' => 'GarageTabs']
                    : ['web' => "/business/{$bizId}", 'mobile' => 'BusinessOverview'],
            ],
            [
                'key' => 'products',
                'label' => 'Products',
                'group' => 'commerce',
                'icon' => 'products',
                'capability' => 'product_management',
                'permission' => 'product.view',
                'route_resolver' => fn () => [
                    'web' => "/business/{$bizId}/products",
                    'mobile' => 'BusinessProducts',
                ],
            ],
            [
                'key' => 'orders',
                'label' => 'Orders',
                'group' => 'commerce',
                'icon' => 'orders',
                'capability' => 'order_management',
                'permission' => 'order.view',
                'route_resolver' => fn () => [
                    'web' => "/business/{$bizId}/orders",
                    'mobile' => 'BusinessOrders',
                ],
            ],
            [
                'key' => 'inventory',
                'label' => 'Inventory',
                'group' => 'commerce',
                'icon' => 'inventory',
                'capability' => 'inventory_management',
                'permission' => 'inventory.view',
                'route_resolver' => fn ($b) => $b->legacy_garage_id
                    ? ['web' => '/garage', 'mobile' => 'GarageTabs']
                    : ['web' => "/business/{$bizId}", 'mobile' => 'BusinessOverview'],
            ],
            [
                'key' => 'payments',
                'label' => 'Payments',
                'group' => 'finance',
                'icon' => 'payments',
                'capability' => 'payment_management',
                'permission' => 'payment.view',
                // Stay in business ops — never deep-link to /customer/payments (that flips account mode to Customer).
                'route_resolver' => fn ($b) => $b->legacy_transport_owner_id
                    ? ['web' => '/owner?tab=earnings', 'mobile' => 'OwnerTabs']
                    : null,
            ],
            [
                'key' => 'members',
                'label' => 'Employees',
                'group' => 'business',
                'icon' => 'team',
                'capability' => null,
                'permission' => 'business.members.view',
                'route_resolver' => fn () => [
                    'web' => "/business/{$bizId}/members",
                    'mobile' => 'BusinessMembers',
                ],
            ],
            [
                'key' => 'reports',
                'label' => 'Reports',
                'group' => 'finance',
                'icon' => 'payments',
                'capability' => 'reporting',
                'permission' => 'report.view',
                'route_resolver' => fn ($b) => $b->legacy_transport_owner_id
                    ? ['web' => '/reports?dashRole=owner', 'mobile' => 'Reports']
                    : ($b->legacy_garage_id
                        ? ['web' => '/reports?dashRole=garage_owner', 'mobile' => 'Reports']
                        : ['web' => '/reports', 'mobile' => 'Reports']),
            ],
        ];
    }
}
