<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\GarageBooking;
use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class PaymentNotificationService
{
    public function __construct(private NotificationService $notifications) {}

    public function notify(Payment $payment, string $event): void
    {
        try {
            $payer = $payment->payer ?: ($payment->payer_id ? User::find($payment->payer_id) : null);
            if (! $payer) {
                return;
            }

            $bookingRef = null;
            $payable = $payment->payable;
            if ($payable instanceof Booking) {
                $bookingRef = $payable->booking_reference;
            } elseif ($payable instanceof GarageBooking) {
                $bookingRef = 'GRG-'.str_pad((string) $payable->id, 6, '0', STR_PAD_LEFT);
            } elseif ($payment->booking_id) {
                $bookingRef = Booking::find($payment->booking_id)?->booking_reference;
            }

            $this->notifications->paymentStatusChanged(
                customerName: $payer->name ?? 'Customer',
                customerEmail: $payer->email,
                customerPhone: $payer->phone ?? null,
                event: $event,
                paymentReference: (string) $payment->payment_reference,
                amountFormatted: 'TZS '.number_format((float) PaymentMoney::toMajor((int) $payment->amount_minor), 0),
                method: (string) ($payment->payment_method ?: '—'),
                status: (string) $payment->status,
                bookingReference: $bookingRef,
                customerWhatsapp: $payer->whatsapp_number ?? null,
            );
        } catch (\Throwable $e) {
            Log::warning('Payment notification failed', [
                'payment_id' => $payment->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
