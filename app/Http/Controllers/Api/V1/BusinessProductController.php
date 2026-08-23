<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessProduct;
use App\Models\BusinessProductCategory;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessProductController extends Controller
{
    public function indexCategories(Request $request, Business $business): JsonResponse
    {
        $this->authorizeView($request);

        $categories = BusinessProductCategory::query()
            ->forBusiness($business->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function storeCategory(Request $request, Business $business): JsonResponse
    {
        $this->authorizeCreate($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $category = BusinessProductCategory::create([
            ...$validated,
            'business_id' => $business->id,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorizeView($request);

        $query = BusinessProduct::query()
            ->forBusiness($business->id)
            ->with('category:id,name')
            ->orderByDesc('updated_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('sku', 'like', $search);
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizeCreate($request);

        $validated = $request->validate([
            'business_product_category_id' => 'nullable|exists:business_product_categories,id',
            'sku' => 'nullable|string|max:64',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'stock_quantity' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:32',
            'status' => 'nullable|in:active,inactive',
        ]);

        if (! empty($validated['business_product_category_id'])) {
            $exists = BusinessProductCategory::query()
                ->forBusiness($business->id)
                ->where('id', $validated['business_product_category_id'])
                ->exists();
            abort_unless($exists, 422, 'Invalid category for this business.');
        }

        $product = BusinessProduct::create([
            ...$validated,
            'business_id' => $business->id,
            'currency' => $validated['currency'] ?? ($business->currency ?: 'TZS'),
            'stock_quantity' => $validated['stock_quantity'] ?? 0,
            'unit' => $validated['unit'] ?? 'pcs',
            'status' => $validated['status'] ?? BusinessProduct::STATUS_ACTIVE,
        ]);

        return response()->json(['data' => $product->load('category:id,name')], 201);
    }

    public function update(Request $request, Business $business, BusinessProduct $product): JsonResponse
    {
        $this->authorizeUpdate($request);
        abort_unless((int) $product->business_id === (int) $business->id, 404);

        $validated = $request->validate([
            'business_product_category_id' => 'nullable|exists:business_product_categories,id',
            'sku' => 'nullable|string|max:64',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'sometimes|numeric|min:0',
            'stock_quantity' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:32',
            'status' => 'nullable|in:active,inactive',
        ]);

        $product->update($validated);

        return response()->json(['data' => $product->fresh()->load('category:id,name')]);
    }

    public function destroy(Request $request, Business $business, BusinessProduct $product): JsonResponse
    {
        $this->authorizeUpdate($request);
        abort_unless((int) $product->business_id === (int) $business->id, 404);

        $product->delete();

        return response()->json(['message' => 'Product removed']);
    }

    private function authorizeView(Request $request): void
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');
        abort_unless($context->can('product.view'), 403);
    }

    private function authorizeCreate(Request $request): void
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');
        abort_unless($context->can('product.create'), 403);
    }

    private function authorizeUpdate(Request $request): void
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');
        abort_unless($context->can('product.update'), 403);
    }
}
