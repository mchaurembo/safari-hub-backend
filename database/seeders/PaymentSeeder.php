<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentMethodCodes;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [PaymentMethodCodes::VISA, 'Visa', 'card', 'card', 10],
            [PaymentMethodCodes::MASTERCARD, 'Mastercard', 'card', 'card', 20],
            [PaymentMethodCodes::MPESA, 'M-Pesa', 'mobile_money', 'vodacom', 30],
            [PaymentMethodCodes::MIXX_BY_YAS, 'Mixx by Yas', 'mobile_money', 'yas', 40],
            [PaymentMethodCodes::AIRTEL_MONEY, 'Airtel Money', 'mobile_money', 'airtel', 50],
            [PaymentMethodCodes::HALOPESA, 'HaloPesa', 'mobile_money', 'halotel', 60],
            [PaymentMethodCodes::BANK_TRANSFER, 'Bank Transfer', 'bank', 'bank', 70],
        ];

        foreach ($methods as [$code, $name, $type, $provider, $sort]) {
            PaymentMethod::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'provider' => $provider,
                    'status' => 'active',
                    'sort_order' => $sort,
                    'configuration' => ['requires_phone' => $type === 'mobile_money'],
                ]
            );
        }

        $defaultDriver = (string) config('payments.default_driver', 'stub');
        $selcomConfigured = (string) config('payments.selcom.vendor') !== '';

        PaymentGateway::updateOrCreate(
            ['code' => 'stub'],
            [
                'name' => 'CHAPA Stub Gateway',
                'driver' => 'stub',
                'status' => 'active',
                'is_default' => $defaultDriver === 'stub',
                'priority' => $defaultDriver === 'stub' ? 1 : 2,
                'supported_methods' => PaymentMethodCodes::all(),
                'configuration' => ['hosted_checkout' => true],
            ]
        );

        PaymentGateway::updateOrCreate(
            ['code' => 'selcom'],
            [
                'name' => 'Selcom Payment Gateway',
                'driver' => 'selcom',
                'status' => $selcomConfigured ? 'active' : 'inactive',
                'is_default' => $defaultDriver === 'selcom',
                'priority' => $defaultDriver === 'selcom' ? 1 : 2,
                'supported_methods' => PaymentMethodCodes::all(),
                'configuration' => [
                    'hosted_checkout' => true,
                    'wallet_push' => true,
                    'vendor' => config('payments.selcom.vendor'),
                ],
            ]
        );

        CommissionRule::updateOrCreate(
            ['code' => 'transport_default'],
            [
                'name' => 'Transport default commission',
                'module' => 'transport',
                'status' => 'active',
                'calculation_type' => 'percent',
                'platform_rate' => 10,
                'platform_fixed_minor' => 0,
                'recipient_rules' => null,
            ]
        );

        CommissionRule::updateOrCreate(
            ['code' => 'garage_default'],
            [
                'name' => 'Garage default commission',
                'module' => 'garage',
                'status' => 'active',
                'calculation_type' => 'percent',
                'platform_rate' => 10,
                'platform_fixed_minor' => 0,
                'recipient_rules' => null,
            ]
        );

        CommissionRule::updateOrCreate(
            ['code' => 'cargo_default'],
            [
                'name' => 'Cargo default commission',
                'module' => 'cargo',
                'status' => 'active',
                'calculation_type' => 'percent',
                'platform_rate' => 10,
                'platform_fixed_minor' => 0,
                'recipient_rules' => null,
            ]
        );
    }
}
