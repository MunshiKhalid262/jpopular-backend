<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Corrects the date a movement is recorded against.
 *
 * The DATE only. Quantity, type and product are not editable: changing any of
 * them would rewrite a balance that other movements were calculated against,
 * and the ledger's whole value is that it is append-only. A wrong quantity is
 * corrected with a compensating movement, which leaves both the mistake and
 * the correction visible.
 *
 * Moving the date changes no arithmetic -- the cached balance is SUM(quantity)
 * regardless of when each row says it happened -- so it is the one field that
 * can be fixed in place.
 */
class UpdateStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::InventoryAdjust->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Past and future both allowed, as when recording one.
            'occurred_at' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'occurred_at.required' => 'A date is required.',
            'occurred_at.date' => 'That is not a date the system can read.',
        ];
    }
}
