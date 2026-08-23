<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy capability pivot writes (user_roles)
    |--------------------------------------------------------------------------
    |
    | When false (default), business-mapped capabilities (owner, garage_owner,
    | driver, …) are derived from business_memberships only — no pivot writes.
    | Platform capabilities (customer, admin) still use user_roles.
    |
    */
    'legacy_capability_pivot_writes' => env('CHAPA_LEGACY_CAPABILITY_WRITES', false),

    /** Capabilities always stored on user_roles pivot. */
    'platform_capabilities' => [
        'customer',
        'admin',
    ],

    /** Capabilities derived from business_memberships (CHAPA RBAC). */
    'business_mapped_capabilities' => [
        'owner',
        'transport_manager',
        'driver',
        'garage_owner',
        'garage_manager',
        'technician',
    ],

];
