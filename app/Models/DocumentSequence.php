<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A locked counter row, one per (type, prefix, financial year) series.
 *
 * Only InvoiceNumberGenerator touches this, and only inside a transaction
 * holding a row lock.
 *
 * @property string $type
 * @property string $prefix
 * @property string $financial_year
 * @property int $last_number
 */
class DocumentSequence extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'prefix',
        'financial_year',
        'last_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }
}
