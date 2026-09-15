<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Enums\PaymentMethod;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Decimal-safe, matching DECIMAL(14,2): validated as a string
             * against an anchored pattern so negatives, excess scale and
             * scientific notation are rejected before the column can round
             * them. That the amount does not exceed the balance is checked by
             * RecordPayment inside the invoice row lock, where it is safe
             * against two payments arriving at once.
             */
            'amount' => [
                'required',
                'regex:'.StoreProductRequest::MONEY_REGEX,
                'numeric',
                'gt:0',
            ],

            // From the enum, never a duplicated list of strings.
            'payment_method' => ['required', 'string', Rule::in(array_column(PaymentMethod::cases(), 'value'))],

            'reference' => ['sometimes', 'nullable', 'string', 'max:80'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Money cannot be received before it is received.
            'received_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'The amount must be a positive figure with at most two decimal places.',
            'amount.gt' => 'The amount must be greater than zero.',
            'payment_method.in' => 'That is not a payment method this system accepts.',
            'received_at.before_or_equal' => 'A payment cannot be dated in the future.',
        ];
    }
}
