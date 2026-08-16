<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Route::query();

        if ($request->has('origin')) {
            $query->where('origin', 'like', '%' . $request->origin . '%');
        }
        if ($request->has('destination')) {
            $query->where('destination', 'like', '%' . $request->destination . '%');
        }

        $routes = $query->orderBy('origin')->get();

        return response()->json(['data' => $routes]);
    }

    /** Transport owners can add passenger routes (origin → destination). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $owner = $user?->transportOwner;
        if (! $owner || $owner->status !== 'approved') {
            return response()->json(['message' => 'Transport owner not approved'], 403);
        }

        $validated = $request->validate([
            'origin' => 'required|string|max:120',
            'destination' => 'required|string|max:120',
            'distance' => 'nullable|numeric|min:0',
            'estimated_time' => 'nullable|string|max:50',
        ]);

        $route = Route::firstOrCreate(
            [
                'origin' => trim($validated['origin']),
                'destination' => trim($validated['destination']),
            ],
            [
                'distance' => $validated['distance'] ?? null,
                'estimated_time' => $validated['estimated_time'] ?? null,
            ]
        );

        return response()->json(['data' => $route], $route->wasRecentlyCreated ? 201 : 200);
    }
}
