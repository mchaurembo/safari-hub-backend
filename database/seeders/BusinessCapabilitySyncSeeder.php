<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\BusinessCapabilityBridgeService;
use Illuminate\Database\Seeder;

class BusinessCapabilitySyncSeeder extends Seeder
{
    public function run(): void
    {
        $bridge = app(BusinessCapabilityBridgeService::class);

        User::query()
            ->whereHas('activeBusinessMemberships')
            ->chunkById(100, function ($users) use ($bridge) {
                foreach ($users as $user) {
                    $bridge->syncLegacyCapabilities($user);
                }
            });

        $this->command?->info('Synced legacy capabilities from business memberships.');
    }
}
