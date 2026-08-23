<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessCategory;
use App\Models\BusinessType;
use App\Models\MembershipRole;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessCatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = BusinessCategory::query()
            ->where('is_active', true)
            ->with(['types' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function types(Request $request): JsonResponse
    {
        $query = BusinessType::query()->where('is_active', true)->with('category');

        if ($request->filled('category_id')) {
            $query->where('business_category_id', $request->integer('category_id'));
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function positions(Request $request): JsonResponse
    {
        $query = Position::query()->where('is_active', true);

        if ($request->filled('business_type_id')) {
            $query->where('business_type_id', $request->integer('business_type_id'));
        }

        if ($request->filled('business_id')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('business_id')
                    ->orWhere('business_id', $request->integer('business_id'));
            });
        } else {
            $query->whereNull('business_id');
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function membershipRoles(): JsonResponse
    {
        $roles = MembershipRole::query()
            ->where('scope', MembershipRole::SCOPE_BUSINESS)
            ->orderBy('id')
            ->get(['id', 'scope', 'code', 'name']);

        return response()->json(['data' => $roles]);
    }
}
