<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CargoRequest;
use App\Models\GarageBooking;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentMoney;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function methods(): JsonResponse
    {
        $methods = PaymentMethod::query()
            ->active()
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'type', 'provider', 'configuration']);

        return response()->json(['data' => $methods]);
    }

    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'payable_type' => 'required|in:booking,garage_booking,transport_booking,cargo_request',
            'payable_id' => 'required|integer',
            'method_code' => 'required|string',
            'phone' => 'nullable|string|max:32',
            'amount' => 'nullable|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'idempotency_key' => 'nullable|string|max:128',
            'return_url' => 'nullable|url',
        ]);

        $payable = $this->resolvePayable($validated['payable_type'], (int) $validated['payable_id']);
        $this->authorizePayable($request, $payable);

        // Server-calculated amount — never trust client amount for known payables.
        $amountMajor = $this->defaultAmount($payable);
        if ((float) $amountMajor <= 0 && isset($validated['amount'])) {
            // Fallback only when payable has no priced amount (admin/manual).
            $amountMajor = $validated['amount'];
        }
        if ((float) $amountMajor <= 0) {
            return response()->json(['message' => 'Payable has no payable amount'], 422);
        }

        $payment = $payments->createAndInitialize([
            'payer' => $request->user(),
            'payable' => $payable,
            'amount_major' => $amountMajor,
            'currency' => $validated['currency'] ?? config('payments.default_currency', 'TZS'),
            'method_code' => $validated['method_code'],
            'phone' => $validated['phone'] ?? $request->user()->phone,
            'idempotency_key' => $validated['idempotency_key'] ?? $request->header('Idempotency-Key'),
            'return_url' => $validated['return_url'] ?? null,
        ]);

        return response()->json(['data' => $this->present($payment)], 201);
    }

    public function show(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->authorize('view', $payment);
        $refresh = $request->boolean('refresh');
        $payment = $payments->getStatus($payment, $refresh);

        return response()->json(['data' => $this->present($payment)]);
    }

    public function retry(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->authorize('view', $payment);

        $validated = $request->validate([
            'method_code' => 'required|string',
            'phone' => 'nullable|string|max:32',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        $newPayment = $payments->retry(
            $payment,
            $validated['method_code'],
            $validated['phone'] ?? null,
            $validated['idempotency_key'] ?? $request->header('Idempotency-Key'),
        );

        return response()->json(['data' => $this->present($newPayment)], 201);
    }

    public function receipt(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return response()->json([
            'data' => [
                'platform' => 'CHAPA',
                'payment_reference' => $payment->payment_reference,
                'gateway_reference' => $payment->gateway_reference,
                'customer' => $payment->payer?->only(['id', 'name', 'email', 'phone']),
                'service' => $payment->payable_type,
                'booking_reference' => $this->bookingReference($payment),
                'payment_method' => $payment->payment_method,
                'amount' => PaymentMoney::toMajor((int) $payment->amount_minor),
                'amount_minor' => (int) $payment->amount_minor,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at,
                'platform_fee_minor' => (int) $payment->allocations()
                    ->where('allocation_type', 'PLATFORM_COMMISSION')
                    ->sum('net_amount_minor'),
            ],
        ]);
    }

    protected function resolvePayable(string $type, int $id): Booking|GarageBooking|CargoRequest
    {
        return match ($type) {
            'booking', 'transport_booking' => Booking::findOrFail($id),
            'garage_booking' => GarageBooking::findOrFail($id),
            'cargo_request' => CargoRequest::findOrFail($id),
            default => abort(422, 'Unsupported payable type'),
        };
    }

    protected function authorizePayable(Request $request, Booking|GarageBooking|CargoRequest $payable): void
    {
        $userId = (int) $request->user()->id;
        if ($payable instanceof Booking && (int) $payable->customer_id !== $userId) {
            abort(403);
        }
        if ($payable instanceof GarageBooking && (int) $payable->customer_id !== $userId) {
            abort(403);
        }
        if ($payable instanceof CargoRequest) {
            if ((int) $payable->customer_id !== $userId) {
                abort(403);
            }
            $this->assertCargoPayable($payable);
        }
    }

    protected function assertCargoPayable(CargoRequest $cargo): void
    {
        if (! in_array($cargo->status, ['quoted', 'accepted'], true)) {
            abort(422, 'Cargo request is not payable at this stage');
        }
        if ((float) $cargo->quoted_price <= 0) {
            abort(422, 'No quoted price for this cargo request');
        }
        if ($cargo->payments()->whereIn('status', PaymentStatuses::successStates())->exists()) {
            abort(422, 'This cargo request is already paid');
        }
    }

    protected function defaultAmount(Booking|GarageBooking|CargoRequest $payable): string
    {
        if ($payable instanceof GarageBooking) {
            return (string) ($payable->amount ?? 0);
        }
        if ($payable instanceof CargoRequest) {
            return (string) ($payable->quoted_price ?? 0);
        }

        $trip = $payable->trip()->first();
        $fare = $trip?->price ?? 0;

        return (string) $fare;
    }

    protected function bookingReference(Payment $payment): ?string
    {
        $payable = $payment->payable;
        if ($payable instanceof Booking) {
            return $payable->booking_reference;
        }
        if ($payable instanceof GarageBooking) {
            return 'GRG-'.str_pad((string) $payable->id, 6, '0', STR_PAD_LEFT);
        }
        if ($payable instanceof CargoRequest) {
            return 'CRG-'.str_pad((string) $payable->id, 6, '0', STR_PAD_LEFT);
        }

        return null;
    }

    protected function present(Payment $payment): array
    {
        $payment->loadMissing('gateway');
        $gatewayDriver = $payment->gateway?->driver ?? (string) config('payments.default_driver', 'stub');

        return [
            'id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'status' => $payment->status,
            'amount' => PaymentMoney::toMajor((int) $payment->amount_minor),
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => $payment->currency,
            'payment_method' => $payment->payment_method,
            'payment_url' => $payment->payment_url,
            'gateway_reference' => $payment->gateway_reference,
            'gateway_driver' => $gatewayDriver,
            'is_test_gateway' => $gatewayDriver === 'stub',
            'payable_type' => $payment->payable_type,
            'payable_id' => $payment->payable_id,
            'booking_id' => $payment->booking_id,
            'initiated_at' => $payment->initiated_at,
            'paid_at' => $payment->paid_at,
            'failure_reason' => $payment->failure_reason,
            'attempts' => $payment->attempts,
        ];
    }
}
