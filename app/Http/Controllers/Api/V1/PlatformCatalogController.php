<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ProductCatalogCategory;
use App\Models\ProductCatalogItem;
use App\Models\ProductUnit;
use App\Models\ServiceCatalogCategory;
use App\Models\ServiceCatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformCatalogController extends Controller
{
    public function productUnits(): JsonResponse
    {
        $units = ProductUnit::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'sort_order']);

        return response()->json(['data' => $units]);
    }

    public function productCategories(Request $request): JsonResponse
    {
        $query = ProductCatalogCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        $this->scopeByBusinessType($query, $request);

        return response()->json([
            'data' => $query->get(['id', 'code', 'name', 'description', 'sort_order', 'applies_to']),
        ]);
    }

    public function productItems(Request $request): JsonResponse
    {
        $query = ProductCatalogItem::query()
            ->where('is_active', true)
            ->whereHas('category', function (Builder $q) use ($request) {
                $q->where('is_active', true);
                $this->scopeByBusinessType($q, $request);
            })
            ->with('category:id,code,name,applies_to')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('product_catalog_category_id', $request->integer('category_id'));
        }

        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', $q)
                    ->orWhere('code', 'like', $q);
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function serviceCategories(Request $request): JsonResponse
    {
        $query = ServiceCatalogCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        $this->scopeByBusinessType($query, $request);

        return response()->json([
            'data' => $query->get(['id', 'code', 'name', 'description', 'sort_order', 'applies_to']),
        ]);
    }

    public function serviceItems(Request $request): JsonResponse
    {
        $query = ServiceCatalogItem::query()
            ->where('is_active', true)
            ->whereHas('category', function (Builder $q) use ($request) {
                $q->where('is_active', true);
                $this->scopeByBusinessType($q, $request);
            })
            ->with('category:id,code,name,applies_to')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('service_catalog_category_id', $request->integer('category_id'));
        }

        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', $q)
                    ->orWhere('code', 'like', $q);
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    private function scopeByBusinessType(Builder $query, Request $request): void
    {
        $typeCode = $this->resolveBusinessTypeCode($request);
        if (! $typeCode) {
            return;
        }

        $query->where(function (Builder $q) use ($typeCode) {
            $q->whereNull('applies_to')
                ->orWhereJsonContains('applies_to', $typeCode);
        });
    }

    private function resolveBusinessTypeCode(Request $request): ?string
    {
        if ($request->filled('business_type')) {
            return (string) $request->string('business_type');
        }

        if ($request->filled('business_id')) {
            $business = Business::query()
                ->with('type:id,code')
                ->find($request->integer('business_id'));

            return $business?->type?->code;
        }

        return null;
    }
}
