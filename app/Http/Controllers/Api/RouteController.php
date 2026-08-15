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
}
