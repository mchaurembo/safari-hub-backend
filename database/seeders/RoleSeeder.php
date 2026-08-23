<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['admin', 'owner', 'driver', 'customer', 'garage_owner', 'technician', 'transport_manager', 'garage_manager'];

        foreach ($roles as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}
