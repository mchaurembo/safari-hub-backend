<?php

namespace App\Http\Controllers\Concerns;

use App\Models\TransportOwner;
use Illuminate\Http\Request;

trait ResolvesTransportFleet
{
    protected function transportFleet(Request $request): ?TransportOwner
    {
        return $request->user()?->accessibleTransportFleet();
    }
}
