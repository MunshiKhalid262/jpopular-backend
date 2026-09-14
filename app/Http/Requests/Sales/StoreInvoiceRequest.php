<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\TaxType;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invoice::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],

            'tax_type' => ['required', 'string', Rule::in(TaxType::values())],

            'invoice_date' => ['required', 'date'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // A flat amount off the whole invoice. The tax engine apportions
            // it across lines before tax.
            'discount_amount' => [
                'sometimes', 'nullable',
                'regex:'.StoreProductRequest::MONEY_REGEX, 'numeric', 'min:0',
            ],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'lines.*.quantity' => [
                'required',
                'regex:'.StoreProductRequest::QUANTITY_REGEX, 'numeric', 'gt:0',
            ],
            // Optional: falls back to the product's current selling price.
            'lines.*.unit_price' => [
                'sometimes', 'nullable',
                'regex:'.StoreProductRequest::MONEY_REGEX, 'numeric', 'min:0',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'An invoice needs at least one line.',
            'lines.min' => 'An invoice needs at least one line.',
            'lines.*.quantity.gt' => 'Each line needs a quantity greater than zero.',
            'lines.*.quantity.regex' => 'A quantity may have at most three decimal places.',
            'lines.*.unit_price.regex' => 'A price may have at most two decimal places.',
            'discount_amount.regex' => 'The discount must be an amount with at most two decimal places.',
        ];
    }

    /**
     * The validated lines as domain inputs.
     *
     * Products are fetched ONCE and keyed by id rather than queried per line,
     * so an invoice with twenty lines is one query instead of twenty.
     *
     * @return list<InvoiceLineInput>
     */
    public function lineInputs(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('lines', []);

        $products = Product::query()
            ->whereIn('id', array_column($rows, 'product_id'))
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            /** @var Product $product */
            $product = $products[(int) $row['product_id']];

            $lines[] = new InvoiceLineInput(
                product: $product,
                quantity: (string) $row['quantity'],
                // Snapshotting the selling price at this moment is what makes
                // a later price change unable to alter this invoice.
                unitPrice: isset($row['unit_price']) && $row['unit_price'] !== null
                    ? (string) $row['unit_price']
                    : (string) $product->selling_price,
                sortOrder: $index,
            );
        }

        return $lines;
    }

    public function invoiceDiscount(): string
    {
        $discount = $this->validated('discount_amount');

        return $discount === null || $discount === '' ? '0' : (string) $discount;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceAttributes(): array
    {
        return $this->safe()->only(['customer_id', 'tax_type', 'invoice_date', 'notes', 'terms']);
    }
}
