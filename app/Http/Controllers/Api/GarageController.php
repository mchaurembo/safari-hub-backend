<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GarageController extends Controller
{
    /**
     * Simple health check for the Garage module.
     * This is intentionally minimal until garage CRUD/booking endpoints are added.
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'module' => 'garage',
            'status' => 'coming_soon',
        ]);
    }
}

