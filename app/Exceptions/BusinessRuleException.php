<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A valid, well-formed request that conflicts with the current state of the
 * system -- as opposed to a validation error (422), which is about the shape of
 * the input.
 *
 * Carries a stable machine-readable code so the frontend can react to specific
 * conflicts without string-matching a human message. Defaults to 409 Conflict.
 *
 * Later modules extend this for INSUFFICIENT_STOCK, INVOICE_ALREADY_FINALIZED,
 * PAYMENT_EXCEEDS_DUE, etc.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = Response::HTTP_CONFLICT,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error(
            message: $this->getMessage(),
            status: $this->status,
            code: $this->errorCode,
        );
    }

    public static function cannotDeactivateSelf(): self
    {
        return new self(
            'You cannot deactivate your own account.',
            'CANNOT_DEACTIVATE_SELF',
        );
    }

    public static function cannotDeleteSelf(): self
    {
        return new self(
            'You cannot delete your own account.',
            'CANNOT_DELETE_SELF',
        );
    }

    public static function lastActiveAdminCannotBeDeactivated(): self
    {
        return new self(
            'This is the last active administrator. Grant the Admin role to another active user first.',
            'LAST_ACTIVE_ADMIN',
        );
    }

    public static function lastActiveAdminCannotLoseAdminRole(): self
    {
        return new self(
            'This is the last active administrator and must keep the Admin role. Grant it to another active user first.',
            'LAST_ACTIVE_ADMIN',
        );
    }

    public static function currentPasswordIncorrect(): self
    {
        return new self(
            'The current password is incorrect.',
            'CURRENT_PASSWORD_INCORRECT',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
