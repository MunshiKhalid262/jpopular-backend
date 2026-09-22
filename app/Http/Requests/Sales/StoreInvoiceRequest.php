<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Data\InvoiceChargeInput;
use App\Domain\Sales\Data\InvoiceLineInput;
use App\Enums\InvoiceType;
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

            // Decides the DOCUMENT, not the tax: a dealer invoice carries the
            // transport block and e-Way Bill page, a customer bill does not.
            'invoice_type' => ['sometimes', 'string', Rule::in(InvoiceType::values())],

            'invoice_date' => ['required', 'date'],

            // Transport and dispatch.
            'eway_bill_no' => ['sometimes', 'nullable', 'string', 'max:20'],
            'vehicle_no' => ['sometimes', 'nullable', 'string', 'max:20'],
            'dispatched_through' => ['sometimes', 'nullable', 'string', 'max:120'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lr_rr_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lr_rr_date' => ['sometimes', 'nullable', 'date'],
            'delivery_note' => ['sometimes', 'nullable', 'string', 'max:60'],
            'delivery_note_date' => ['sometimes', 'nullable', 'date'],
            'dispatch_doc_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'buyer_order_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'buyer_order_date' => ['sometimes', 'nullable', 'date'],
            'terms_of_delivery' => ['sometimes', 'nullable', 'string', 'max:200'],
            'mode_of_payment' => ['sometimes', 'nullable', 'string', 'max:120'],
            'other_references' => ['sometimes', 'nullable', 'string', 'max:200'],

            /*
             * e-Invoice fields, typed in from the government portal. There is
             * no IRP integration, so these are accepted as given and never
             * generated here.
             */
            'irn' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ack_no' => ['sometimes', 'nullable', 'string', 'max:32'],
            'ack_date' => ['sometimes', 'nullable', 'date'],

            // Optional extra charges, each taxed at its own rate.
            'charges' => ['sometimes', 'array', 'max:20'],
            'charges.*.description' => ['required', 'string', 'max:200'],
            'charges.*.amount' => [
                'required',
                'regex:'.StoreProductRequest::MONEY_REGEX, 'numeric', 'gt:0',
            ],
            'charges.*.gst_rate' => [
                'sometimes', 'nullable',
                'regex:'.StoreProductRequest::RATE_REGEX, 'numeric', 'between:0,100',
            ],
            'charges.*.hsn_code' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^[0-9]{4,8}$/'],

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
     * The validated extra charges as domain inputs.
     *
     * @return list<InvoiceChargeInput>
     */
    public function chargeInputs(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('charges', []);

        $charges = [];

        foreach (array_values($rows) as $index => $row) {
            $charges[] = new InvoiceChargeInput(
                description: (string) $row['description'],
                amount: (string) $row['amount'],
                gstRate: isset($row['gst_rate']) && $row['gst_rate'] !== null ? (string) $row['gst_rate'] : '0',
                hsnCode: $row['hsn_code'] ?? null,
                sortOrder: $index,
            );
        }

        return $charges;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceAttributes(): array
    {
        return $this->safe()->only([
            'customer_id', 'invoice_type', 'tax_type', 'invoice_date', 'notes', 'terms',
            'eway_bill_no', 'vehicle_no', 'dispatched_through', 'destination',
            'lr_rr_no', 'lr_rr_date', 'delivery_note', 'delivery_note_date',
            'dispatch_doc_no', 'buyer_order_no', 'buyer_order_date',
            'terms_of_delivery', 'mode_of_payment', 'other_references',
            'irn', 'ack_no', 'ack_date',
        ]);
    }
}
