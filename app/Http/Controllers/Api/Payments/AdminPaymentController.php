<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Jobs\Payments\ProcessPayout;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payments\PaymentExpiryService;
use App\Services\Payments\PaymentMoney;
use App\Services\Payments\PayoutService;
use App\Services\Payments\ReconciliationService;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $query = Payment::query()
            ->with(['payer:id,name,email', 'method', 'gateway'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->method_code, fn ($q, $m) => $q->where('payment_method', $m))
            ->when($request->reference, fn ($q, $r) => $q->where('payment_reference', 'like', "%{$r}%"))
            ->when($request->from, fn ($q, $f) => $q->where('created_at', '>=', $f))
            ->when($request->to, fn ($q, $t) => $q->where('created_at', '<=', $t))
            ->orderByDesc('id');

        return response()->json($query->paginate(50));
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json([
            'data' => $payment->load([
                'payer', 'method', 'gateway', 'attempts', 'transactions', 'allocations', 'refunds',
            ]),
        ]);
    }

    public function dashboard(Request $request, ReconciliationService $reconciliation, PaymentExpiryService $expiry): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json([
            'data' => array_merge(
                $reconciliation->summary($request->from, $request->to),
                [
                    'stale_pending_payments' => $expiry->countStale(),
                    'pending_expiry_hours' => (int) config('payments.pending_expiry_hours', 24),
                ]
            ),
        ]);
    }

    public function expireStale(Request $request, PaymentExpiryService $expiry): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'hours' => 'nullable|integer|min:0|max:720',
        ]);

        $count = $expiry->expireStale(
            array_key_exists('hours', $validated) ? (int) $validated['hours'] : null
        );

        return response()->json([
            'message' => $count > 0
                ? "{$count} abandoned payment(s) marked as EXPIRED."
                : 'No matching pending payments to expire.',
            'expired_count' => $count,
        ]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json(
            Refund::query()->with('payment')->orderByDesc('id')->paginate(50)
        );
    }

    public function requestRefund(Request $request, Payment $payment, RefundService $refunds): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
            'process' => 'sometimes|boolean',
        ]);

        $refund = $refunds->request(
            $payment,
            PaymentMoney::toMinor($validated['amount']),
            $request->user(),
            $validated['reason'] ?? null,
        );

        if ($request->boolean('process', true)) {
            $refund = $refunds->process($refund, $request->user());
        }

        return response()->json(['data' => $refund], 201);
    }

    public function payouts(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json(
            Payout::query()->with('recipient:id,name,email')->orderByDesc('id')->paginate(50)
        );
    }

    public function processPayout(Request $request, Payout $payout): JsonResponse
    {
        $this->ensureAdmin($request);
        ProcessPayout::dispatch($payout->id);

        return response()->json(['message' => 'Payout queued']);
    }

    public function createPayout(Request $request, PayoutService $payouts): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'payout_method' => 'nullable|string',
            'process' => 'sometimes|boolean',
        ]);

        $user = User::findOrFail($validated['recipient_id']);
        $payout = $payouts->request(
            $user,
            PaymentMoney::toMinor($validated['amount']),
            strtoupper($validated['currency'] ?? 'TZS'),
            $validated['payout_method'] ?? null,
        );

        if ($request->boolean('process')) {
            $payout = $payouts->process($payout);
        }

        return response()->json(['data' => $payout], 201);
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()?->hasCapability('admin')) {
            abort(403, 'Admin access required');
        }
    }
}
