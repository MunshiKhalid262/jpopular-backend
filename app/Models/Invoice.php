<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\SupplyType;
use App\Enums\TaxType;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string|null $invoice_number
 * @property TaxType $tax_type
 * @property InvoiceStatus $status
 * @property PaymentStatus $payment_status
 * @property SupplyType|null $supply_type
 * @property string $grand_total
 * @property string $paid_amount
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Deliberately narrow.
     *
     * Every monetary column, the invoice number, both status columns and the
     * finalize/cancel audit fields are ABSENT: they are computed or set by the
     * Sales Actions, never by client input. A request that could set
     * `grand_total` or `status` directly would bypass the whole tax engine.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'party_address',
        'invoice_type',
        'tax_type',
        'invoice_date',
        'notes',
        'terms',

        // Consignee and transport: operator-entered document detail, not
        // money. prices_include_tax is absent deliberately -- it is snapshotted
        // by the Action from settings, never chosen per request.
        'eway_bill_no',
        'vehicle_no',
        'dispatched_through',
        'destination',
        'lr_rr_no',
        'lr_rr_date',
        'delivery_note',
        'delivery_note_date',
        'dispatch_doc_no',
        'buyer_order_no',
        'buyer_order_date',
        'terms_of_delivery',
        'mode_of_payment',
        'other_references',
        'irn',
        'ack_no',
        'ack_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_type' => InvoiceType::class,
            'prices_include_tax' => 'boolean',
            'lr_rr_date' => 'date',
            'delivery_note_date' => 'date',
            'buyer_order_date' => 'date',
            'ack_date' => 'date',
            'tax_type' => TaxType::class,
            'status' => InvoiceStatus::class,
            'payment_status' => PaymentStatus::class,
            'supply_type' => SupplyType::class,
            'invoice_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<InvoiceCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(InvoiceCharge::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('received_at');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isFinalized(): bool
    {
        return $this->status === InvoiceStatus::Finalized;
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    public function chargesGst(): bool
    {
        return $this->tax_type === TaxType::Gst;
    }

    public function isDealerInvoice(): bool
    {
        return $this->invoice_type === InvoiceType::Dealer;
    }

    /**
     * Whether the transport/dispatch block has anything to show.
     *
     * A dealer invoice with no transport details entered yet should not print
     * a grid of empty boxes.
     */
    public function hasTransportDetails(): bool
    {
        foreach ([
            'eway_bill_no', 'vehicle_no', 'dispatched_through', 'destination',
            'lr_rr_no', 'delivery_note', 'dispatch_doc_no', 'buyer_order_no',
            'terms_of_delivery', 'mode_of_payment', 'other_references',
        ] as $field) {
            if (filled($this->{$field})) {
                return true;
            }
        }

        return false;
    }

    public function hasEInvoiceDetails(): bool
    {
        return filled($this->irn) || filled($this->ack_no);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Finalized->value)
            ->whereColumn('paid_amount', '<', 'grand_total');
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeFinalized(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Finalized->value);
    }
}
