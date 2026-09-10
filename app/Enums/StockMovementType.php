<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every way stock can move.
 *
 * `direction()` is the single source of truth for the sign of a movement, so
 * no caller has to remember whether a given type adds or removes -- getting
 * that wrong once would corrupt the ledger permanently.
 */
enum StockMovementType: string
{
    case OpeningStock = 'opening_stock';
    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case InvoiceSale = 'invoice_sale';
    case InvoiceCancel = 'invoice_cancel';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** +1 adds to stock, -1 removes from it. */
    public function direction(): int
    {
        return match ($this) {
            self::OpeningStock,
            self::StockIn,
            self::AdjustmentIn,
            self::InvoiceCancel => 1,

            self::StockOut,
            self::AdjustmentOut,
            self::InvoiceSale => -1,
        };
    }

    public function increasesStock(): bool
    {
        return $this->direction() === 1;
    }

    public function label(): string
    {
        return match ($this) {
            self::OpeningStock => 'Opening stock',
            self::StockIn => 'Stock in',
            self::StockOut => 'Stock out',
            self::AdjustmentIn => 'Adjustment (increase)',
            self::AdjustmentOut => 'Adjustment (decrease)',
            self::InvoiceSale => 'Invoice sale',
            self::InvoiceCancel => 'Invoice cancelled',
        };
    }

    /**
     * Types an operator may create directly through the inventory API.
     *
     * invoice_sale and invoice_cancel are deliberately absent: those are
     * written only by the invoice Actions, so stock can never be moved behind
     * an invoice's back.
     *
     * @return list<string>
     */
    public static function manualTypes(): array
    {
        return array_map(static fn (self $case): string => $case->value, [
            self::OpeningStock,
            self::StockIn,
            self::StockOut,
            self::AdjustmentIn,
            self::AdjustmentOut,
        ]);
    }

    /** A reason is mandatory for these: the number changed without a document. */
    public function requiresNote(): bool
    {
        return match ($this) {
            self::AdjustmentIn, self::AdjustmentOut, self::StockOut => true,
            default => false,
        };
    }
}
