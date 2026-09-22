<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $gstin
 * @property string|null $state_code
 * @property bool $is_active
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    /**
     * A new customer is a walk-in until told otherwise.
     *
     * Declared here as well as on the column: a freshly created model does not
     * read the database default back, so without this `type` is null in memory
     * and anything casting it -- the API resource, for one -- falls over.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => CustomerType::Customer->value,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'state_code',
        'pincode',
        'gstin',
        'notes',
        'is_active',

        // Dealer dispatch defaults, copied onto an invoice when the dealer is
        // chosen and editable there afterwards.
        'default_dispatched_through',
        'default_destination',
        'default_terms_of_delivery',
        'default_mode_of_payment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeDealers(Builder $query): Builder
    {
        return $query->where('type', CustomerType::Dealer->value);
    }

    public function isDealer(): bool
    {
        return $this->type === CustomerType::Dealer;
    }

    /**
     * The dispatch details to copy onto a new dealer invoice.
     *
     * Only the fields that genuinely repeat. The e-Way Bill, vehicle, LR-RR
     * and order numbers differ on every trip, so defaulting them would put
     * last week's lorry on this week's invoice.
     *
     * @return array<string, string|null>
     */
    public function invoiceDefaults(): array
    {
        return [
            'dispatched_through' => $this->default_dispatched_through,
            // Falls back to the city, which is where the goods go when nobody
            // has said otherwise. A blank Destination on a dealer invoice is a
            // gap the operator has to fill by hand every single time.
            'destination' => $this->default_destination ?: $this->city,
            'terms_of_delivery' => $this->default_terms_of_delivery,
            'mode_of_payment' => $this->default_mode_of_payment,
        ];
    }

    public function hasInvoices(): bool
    {
        return $this->invoices()->withTrashed()->exists();
    }

    /**
     * Whether this customer carries enough address detail to raise a GST
     * invoice. The place of supply decides CGST+SGST vs IGST, so without a
     * state code the tax split cannot be determined at all.
     */
    public function canBeBilledWithGst(): bool
    {
        return $this->state_code !== null && $this->state_code !== '';
    }
}
