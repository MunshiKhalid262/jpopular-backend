<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\StockMovementType;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Models\StockMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StockMovement::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * manualTypes() is the single source of truth. invoice_sale and
             * invoice_cancel are absent from it deliberately: those are
             * written only by the invoice Actions, so stock can never be
             * moved behind an invoice's back through this endpoint.
             */
            'type' => ['required', 'string', Rule::in(StockMovementType::manualTypes())],

            /*
             * Decimal-safe, matching DECIMAL(12,3) and the catalog's
             * convention: validated as a string against an anchored pattern so
             * negatives, excess scale and scientific notation are rejected
             * before the column can silently round them.
             *
             * The ledger also refuses a non-positive quantity, but failing
             * here returns a 422 with a field error instead of a 500.
             */
            'quantity' => [
                'required',
                'regex:'.StoreProductRequest::QUANTITY_REGEX,
                'numeric',
                'gt:0',
            ],

            // A reason is mandatory where the enum says the number changed
            // without a source document. Derived, never duplicated.
            'note' => [
                Rule::requiredIf(fn (): bool => $this->movementType()?->requiresNote() ?? false),
                'nullable',
                'string',
                'max:500',
            ],

            'unit_cost' => [
                'sometimes', 'nullable',
                'regex:'.StoreProductRequest::MONEY_REGEX,
                'numeric', 'min:0',
            ],

            // Business date, which may differ from the insert time. Never in
            // the future: stock cannot move before it happens.
            'occurred_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'That is not a stock movement an operator can record directly.',
            'quantity.regex' => 'The quantity must be a positive number with at most three decimal places.',
            'quantity.gt' => 'The quantity must be greater than zero.',
            'note.required' => 'A note is required for this movement type, so the change is auditable.',
            'occurred_at.before_or_equal' => 'The movement date cannot be in the future.',
        ];
    }

    /** The validated type as an enum, for the controller and the note rule. */
    public function movementType(): ?StockMovementType
    {
        $type = $this->input('type');

        return is_string($type) ? StockMovementType::tryFrom($type) : null;
    }
}
