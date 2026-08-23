<?php

namespace App\Http\Controllers\Concerns;

use App\Models\TransportOwner;
use App\Services\LegacyBusinessAccessService;
use Illuminate\Http\Request;

trait ResolvesTransportFleet
{
    protected function transportFleet(Request $request): ?TransportOwner
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        return app(LegacyBusinessAccessService::class)->transportFleetForRequest($user, $request);
    }
}
