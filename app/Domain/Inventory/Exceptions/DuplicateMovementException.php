<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Exceptions\BusinessRuleException;

/**
 * Raised when the UNIQUE(type, reference_type, reference_id) index on
 * `stock_movements` rejects an insert -- meaning this exact movement has
 * already been recorded.
 *
 * This is the idempotency guarantee working as intended: a repeated
 * finalization cannot deduct stock twice. Surfaced as a business conflict
 * rather than a database error.
 */
final class DuplicateMovementException extends BusinessRuleException {}
