<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\CargoRequest;
use App\Models\GarageBooking;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;

/**
 * Confirms business entities only after server-side payment SUCCESS.
 */
class PayableConfirmationService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    public function confirm(Payment $payment): void
    {
        if (! $payment->isSuccessful()) {
            return;
        }

        $payable = $payment->payable;
        if (! $payable && $payment->booking_id) {
            $payable = Booking::find($payment->booking_id);
        }

        if (! $payable instanceof Model) {
            return;
        }

        if ($payable instanceof Booking) {
            if (! in_array($payable->status, ['paid', 'completed'], true)) {
                $old = $payable->status;
                $payable->update(['status' => 'paid']);
                $this->audit->log('payment.booking_confirmed', $payable, ['status' => $old], ['status' => 'paid']);
            }

            return;
        }

        if ($payable instanceof GarageBooking) {
            if ($payable->status === 'pending') {
                $old = $payable->status;
                $payable->update(['status' => 'confirmed']);
                $this->audit->log('payment.garage_booking_confirmed', $payable, ['status' => $old], ['status' => 'confirmed']);
            }

            return;
        }

        if ($payable instanceof CargoRequest) {
            if ($payable->status === 'quoted') {
                $old = $payable->status;
                $payable->update(['status' => 'accepted']);
                $payable->load(['driver.user', 'customer']);

                $this->audit->log('payment.cargo_quote_accepted', $payable, ['status' => $old], ['status' => 'accepted']);

                $driver = $payable->driver;
                if ($driver?->user) {
                    $this->notifications->driverQuoteAccepted(
                        driverName: $driver->user->name,
                        driverEmail: $driver->user->email,
                        driverPhone: $driver->user->phone,
                        customerName: $payable->customer?->name ?? 'Customer',
                        pickupAddress: $payable->pickup_address,
                        destAddress: $payable->dest_address,
                        quotedPrice: (float) $payable->quoted_price,
                        driverWhatsapp: $driver->user->whatsapp_number,
                    );
                }
            }
        }
    }
}
