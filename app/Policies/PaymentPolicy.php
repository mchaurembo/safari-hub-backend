<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        if ($user->hasCapability('admin')) {
            return true;
        }

        return (int) $payment->payer_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasCapability('admin');
    }

    public function manage(User $user): bool
    {
        return $user->hasCapability('admin');
    }
}
