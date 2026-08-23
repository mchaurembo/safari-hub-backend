<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessOrder;
use App\Models\BusinessOrderItem;
use App\Models\BusinessProduct;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessOrderController extends Controller
{
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorizeView($request);

        $query = BusinessOrder::query()
            ->forBusiness($business->id)
            ->with(['customer:id,name,email', 'items'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    public function show(Request $request, Business $business, BusinessOrder $order): JsonResponse
    {
        $this->authorizeView($request);
        abort_unless((int) $order->business_id === (int) $business->id, 404);

        return response()->json([
            'data' => $order->load(['customer:id,name,email,phone', 'items.product:id,name,sku']),
        ]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizeCreate($request);

        $validated = $request->validate([
            'customer_user_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.business_product_id' => 'nullable|exists:business_products,id',
            'items.*.name' => 'required_without:items.*.business_product_id|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $order = DB::transaction(function () use ($business, $validated, $request) {
            /** @var BusinessContext $context */
            $context = $request->attributes->get('business_context');

            $order = BusinessOrder::create([
                'business_id' => $business->id,
                'business_branch_id' => $context->branchId(),
                'customer_user_id' => $validated['customer_user_id'] ?? null,
                'order_number' => $this->nextOrderNumber($business->id),
                'status' => BusinessOrder::STATUS_PENDING,
                'currency' => $business->currency ?: 'TZS',
                'notes' => $validated['notes'] ?? null,
            ]);

            $subtotal = 0;
            foreach ($validated['items'] as $row) {
                $product = null;
                if (! empty($row['business_product_id'])) {
                    $product = BusinessProduct::query()
                        ->forBusiness($business->id)
                        ->findOrFail($row['business_product_id']);
                }

                $qty = (float) $row['quantity'];
                $unitPrice = isset($row['unit_price'])
                    ? (float) $row['unit_price']
                    : (float) ($product?->price ?? 0);
                $lineTotal = round($qty * $unitPrice, 2);

                BusinessOrderItem::create([
                    'business_order_id' => $order->id,
                    'business_product_id' => $product?->id,
                    'name' => $product?->name ?? $row['name'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);

                if ($product) {
                    $product->decrement('stock_quantity', $qty);
                }

                $subtotal += $lineTotal;
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            return $order->fresh(['items', 'customer:id,name,email']);
        });

        return response()->json(['data' => $order], 201);
    }

    public function updateStatus(Request $request, Business $business, BusinessOrder $order): JsonResponse
    {
        $this->authorizeCreate($request);
        abort_unless((int) $order->business_id === (int) $business->id, 404);

        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,fulfilled,cancelled',
        ]);

        $order->update([
            'status' => $validated['status'],
            'fulfilled_at' => $validated['status'] === BusinessOrder::STATUS_FULFILLED ? now() : $order->fulfilled_at,
        ]);

        return response()->json(['data' => $order->fresh(['items'])]);
    }

    private function nextOrderNumber(int $businessId): string
    {
        return 'ORD-'.$businessId.'-'.strtoupper(Str::random(8));
    }

    private function authorizeView(Request $request): void
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');
        abort_unless($context->can('order.view'), 403);
    }

    private function authorizeCreate(Request $request): void
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');
        abort_unless($context->can('order.create'), 403);
    }
}
