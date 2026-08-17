<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\CargoRequest;
use App\Models\CommissionRule;
use App\Models\GarageBooking;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\TransportOwner;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

class AllocationService
{
    public function allocateForSuccessfulPayment(Payment $payment): void
    {
        if ($payment->allocations()->exists()) {
            return; // idempotent
        }

        $module = $this->resolveModule($payment);
        $rule = $this->resolveRule($module);
        $gross = (int) $payment->amount_minor;
        $currency = $payment->currency ?: config('payments.default_currency', 'TZS');

        $platformMinor = $this->platformCommission($rule, $gross);
        $remaining = max(0, $gross - $platformMinor);

        DB::transaction(function () use ($payment, $rule, $gross, $platformMinor, $remaining, $currency, $module) {
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'recipient_type' => null,
                'recipient_id' => null,
                'allocation_type' => 'PLATFORM_COMMISSION',
                'gross_amount_minor' => $gross,
                'commission_amount_minor' => $platformMinor,
                'net_amount_minor' => $platformMinor,
                'currency' => $currency,
                'status' => 'AVAILABLE',
                'metadata' => ['rule' => $rule?->code, 'module' => $module],
            ]);

            $recipients = $this->resolveRecipients($payment, $rule, $remaining);
            $distributed = 0;
            $lastIndex = count($recipients) - 1;

            foreach ($recipients as $i => $recipient) {
                $share = $i === $lastIndex
                    ? max(0, $remaining - $distributed)
                    : (int) $recipient['amount_minor'];
                $distributed += $share;

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'recipient_type' => $recipient['type'],
                    'recipient_id' => $recipient['id'],
                    'allocation_type' => $recipient['allocation_type'],
                    'gross_amount_minor' => $gross,
                    'commission_amount_minor' => $platformMinor,
                    'net_amount_minor' => $share,
                    'currency' => $currency,
                    'status' => 'AVAILABLE',
                    'metadata' => $recipient['metadata'] ?? null,
                ]);
            }
        });
    }

    protected function resolveModule(Payment $payment): string
    {
        $payable = $payment->payable;
        if ($payable instanceof Booking) {
            return 'transport';
        }
        if ($payable instanceof GarageBooking) {
            return 'garage';
        }
        if ($payable instanceof CargoRequest) {
            return 'cargo';
        }

        return (string) ($payment->metadata['module'] ?? '*');
    }

    protected function resolveRule(string $module): ?CommissionRule
    {
        return CommissionRule::query()
            ->where('status', 'active')
            ->where(function ($q) use ($module) {
                $q->where('module', $module)->orWhere('module', '*');
            })
            ->orderByRaw('CASE WHEN module = ? THEN 0 ELSE 1 END', [$module])
            ->first();
    }

    protected function platformCommission(?CommissionRule $rule, int $gross): int
    {
        if (! $rule) {
            $percent = (string) config('payments.commission.default_platform_percent', '10');

            return PaymentMoney::percentOf($gross, $percent);
        }

        if ($rule->calculation_type === 'fixed') {
            return min($gross, (int) $rule->platform_fixed_minor);
        }

        return PaymentMoney::percentOf($gross, (string) $rule->platform_rate);
    }

    /**
     * @return list<array{type: ?string, id: ?int, allocation_type: string, amount_minor: int, metadata?: array}>
     */
    protected function resolveRecipients(Payment $payment, ?CommissionRule $rule, int $remaining): array
    {
        $payable = $payment->payable ?? $payment->booking;
        $custom = $rule?->recipient_rules;

        if (is_array($custom) && $custom !== []) {
            return $this->fromCustomRules($custom, $remaining, $payment);
        }

        if ($payable instanceof Booking) {
            $ownerUserId = $this->transportOwnerUserId($payable);
            if ($ownerUserId) {
                return [[
                    'type' => User::class,
                    'id' => $ownerUserId,
                    'allocation_type' => 'PROVIDER',
                    'amount_minor' => $remaining,
                    'metadata' => ['role' => 'transport_owner'],
                ]];
            }
        }

        if ($payable instanceof GarageBooking) {
            $garage = $payable->garage;
            $ownerUserId = $garage?->owner_id;
            if ($ownerUserId) {
                return [[
                    'type' => User::class,
                    'id' => $ownerUserId,
                    'allocation_type' => 'GARAGE',
                    'amount_minor' => $remaining,
                    'metadata' => ['garage_id' => $garage?->id],
                ]];
            }
        }

        if ($payable instanceof CargoRequest) {
            $ownerUserId = $this->cargoOwnerUserId($payable);
            if ($ownerUserId) {
                return [[
                    'type' => User::class,
                    'id' => $ownerUserId,
                    'allocation_type' => 'PROVIDER',
                    'amount_minor' => $remaining,
                    'metadata' => ['role' => 'transport_owner', 'cargo_request_id' => $payable->id],
                ]];
            }
        }

        return [[
            'type' => null,
            'id' => null,
            'allocation_type' => 'PROVIDER',
            'amount_minor' => $remaining,
            'metadata' => ['note' => 'unassigned_provider'],
        ]];
    }

    protected function fromCustomRules(array $rules, int $remaining, Payment $payment): array
    {
        $out = [];
        $sumShares = 0;
        foreach ($rules as $rule) {
            $share = isset($rule['share_percent'])
                ? PaymentMoney::percentOf($remaining, (string) $rule['share_percent'])
                : (int) ($rule['share_fixed_minor'] ?? 0);
            $sumShares += $share;
            $out[] = [
                'type' => $rule['recipient_type'] ?? User::class,
                'id' => $rule['recipient_id'] ?? null,
                'allocation_type' => $rule['allocation_type'] ?? 'PROVIDER',
                'amount_minor' => $share,
                'metadata' => $rule['metadata'] ?? null,
            ];
        }

        if ($out !== [] && $sumShares < $remaining) {
            $out[count($out) - 1]['amount_minor'] += ($remaining - $sumShares);
        }

        return $out;
    }

    protected function transportOwnerUserId(Booking $booking): ?int
    {
        $trip = $booking->relationLoaded('trip') ? $booking->trip : $booking->trip()->with('vehicle.owner')->first();
        if (! $trip instanceof Trip) {
            return null;
        }
        $vehicle = $trip->vehicle;
        if (! $vehicle instanceof Vehicle) {
            $vehicle = Vehicle::find($trip->vehicle_id);
        }
        $owner = $vehicle?->owner;
        if ($owner instanceof TransportOwner) {
            return $owner->user_id;
        }
        if (is_numeric($vehicle?->owner_id)) {
            return TransportOwner::find($vehicle->owner_id)?->user_id;
        }

        return null;
    }

    protected function cargoOwnerUserId(CargoRequest $cargo): ?int
    {
        $driver = $cargo->relationLoaded('driver') ? $cargo->driver : $cargo->driver()->with('owner')->first();
        $owner = $driver?->owner;
        if ($owner instanceof TransportOwner) {
            return $owner->user_id;
        }
        if (is_numeric($driver?->owner_id)) {
            return TransportOwner::find($driver->owner_id)?->user_id;
        }

        return null;
    }
}
