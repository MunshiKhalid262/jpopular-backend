<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::PaymentsView->value);
    }

    public function view(User $actor, Payment $payment): bool
    {
        return $actor->can(PermissionName::PaymentsView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::PaymentsRecord->value);
    }

    /**
     * Voiding reverses money already recorded as received, so it is a separate
     * and higher privilege than taking a payment.
     */
    public function void(User $actor, Payment $payment): bool
    {
        return $actor->can(PermissionName::PaymentsVoid->value);
    }
}
