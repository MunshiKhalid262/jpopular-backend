<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Single-row settings for the business.
 *
 * `current()` is the only supported accessor and is cached, because the
 * seller's state code is read on every GST calculation.
 *
 * @property string $business_name
 * @property string|null $gstin
 * @property string|null $state_code
 * @property string $invoice_prefix
 * @property bool $enable_round_off
 */
class BusinessSettings extends Model
{
    /** Fixed id: the unique `singleton` column enforces a single row. */
    public const SINGLETON_ID = 1;

    private const CACHE_KEY = 'business_settings';

    protected $table = 'business_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_name',
        'legal_name',
        'gstin',
        'pan',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'state_code',
        'pincode',
        'phone',
        'email',
        'website',
        'invoice_prefix',
        'invoice_terms',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_branch',
        'upi_id',
        'default_gst_rate',
        'default_tax_type',
        'currency',
        'enable_round_off',
        'financial_year_start_month',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_gst_rate' => 'decimal:2',
            'enable_round_off' => 'boolean',
            'financial_year_start_month' => 'integer',
        ];
    }

    /**
     * The settings row, creating a placeholder on first access so a fresh
     * install is never missing it.
     */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = Cache::rememberForever(
            self::CACHE_KEY,
            fn (): self => self::firstOrCreate(
                ['id' => self::SINGLETON_ID],
                ['business_name' => config('app.name', 'JPopular')],
            ),
        );

        return $settings;
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        // Any write invalidates the cache, so a settings change takes effect
        // on the next invoice rather than after a deploy.
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }

    /**
     * Whether the business is configured well enough to issue a GST invoice.
     * Without a seller state code the CGST/SGST vs IGST split is undecidable.
     */
    public function canIssueGstInvoices(): bool
    {
        return $this->state_code !== null && $this->state_code !== '';
    }
}
