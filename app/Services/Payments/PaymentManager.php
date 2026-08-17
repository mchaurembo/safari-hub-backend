<?php

namespace App\Services\Payments;

use App\Models\PaymentGateway;
use App\Services\Payments\Gateways\StubPaymentGateway;
use InvalidArgumentException;

class PaymentManager
{
    /** @var array<string, class-string<PaymentGatewayInterface>> */
    protected array $drivers;

    public function __construct()
    {
        $this->drivers = config('payments.drivers', [
            'stub' => StubPaymentGateway::class,
        ]);
    }

    public function driver(?string $driver = null): PaymentGatewayInterface
    {
        $driver = $driver ?: (string) config('payments.default_driver', 'stub');

        if (! isset($this->drivers[$driver])) {
            throw new InvalidArgumentException("Payment driver [{$driver}] is not registered.");
        }

        return app($this->drivers[$driver]);
    }

    public function forGateway(?PaymentGateway $gateway): PaymentGatewayInterface
    {
        if (! $gateway) {
            return $this->driver();
        }

        return $this->driver($gateway->driver);
    }

    public function forMethodCode(string $methodCode): PaymentGatewayInterface
    {
        $map = config('payments.method_gateway_map', []);
        $driver = $map[$methodCode] ?? null;

        if ($driver) {
            return $this->driver($driver);
        }

        $gateway = PaymentGateway::query()
            ->where('status', 'active')
            ->orderBy('priority')
            ->get()
            ->first(function (PaymentGateway $g) use ($methodCode) {
                $methods = $g->supported_methods ?? [];

                return empty($methods) || in_array($methodCode, $methods, true);
            });

        if ($gateway) {
            return $this->forGateway($gateway);
        }

        return $this->driver();
    }

    public function resolveGatewayModel(?string $driver = null): ?PaymentGateway
    {
        $driver = $driver ?: (string) config('payments.default_driver', 'stub');

        return PaymentGateway::query()
            ->where('driver', $driver)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->first();
    }
}
