<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces the architecture's strict SKU policy: a SKU is unique across ALL
 * products, including soft-deleted ones.
 *
 * Rule::unique would already span archived rows (it applies no deleted_at
 * filter), but this rule additionally compares case-insensitively. MySQL's
 * utf8mb4_unicode_ci collation would catch case variants on its own; SQLite --
 * which the test suite uses -- would not, so doing it explicitly keeps the
 * behaviour identical in both, rather than passing tests and diverging in
 * production.
 */
class UniqueSku implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreProductId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return; // `required` reports this
        }

        $exists = Product::withTrashed()
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower(trim($value))])
            ->when(
                $this->ignoreProductId !== null,
                fn ($query) => $query->whereKeyNot($this->ignoreProductId)
            )
            ->exists();

        if ($exists) {
            $fail('This SKU is already used by another product, including archived products.');
        }
    }
}
