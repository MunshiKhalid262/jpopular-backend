<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHITECTURE-V1.md section 4.10.
 *
 * Every displayed value is SNAPSHOTTED at finalization: name, SKU, HSN, unit,
 * price, GST rate and all computed amounts.
 *
 * This is a legal requirement, not a convenience. A reprinted invoice from
 * months ago must show the price and rate actually charged; joining live
 * product data would silently reprint different numbers on a filed tax
 * document if the product were later edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            // Composition: an item has no meaning without its invoice.
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            // A REPORTING link only. Display values come from the snapshot
            // columns, so this being null never corrupts a historical invoice.
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();

            // --- snapshot ------------------------------------------------
            $table->string('product_name', 200);
            $table->string('sku', 64);
            $table->string('hsn_code', 8)->nullable();
            $table->string('unit', 16);

            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 2);

            /*
             * The product's configured rate at the time of sale.
             *
             * On a NON-GST invoice this is retained for internal reference but
             * every *_amount below is zero: the snapshot records what the rate
             * was, never what was charged.
             */
            $table->decimal('gst_rate', 5, 2)->default(0);

            $table->decimal('line_subtotal', 14, 2);
            // This line's share of the invoice-level discount, apportioned
            // pro-rata so per-line tax is computed on the discounted value.
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2);

            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);

            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('product_id');
            // GST summary reporting groups by HSN.
            $table->index('hsn_code');
        });

        if (DB::getDriverName() === 'mysql') {
            foreach ([
                'invoice_items_quantity_positive' => 'quantity > 0',
                'invoice_items_unit_price_non_negative' => 'unit_price >= 0',
                'invoice_items_discount_non_negative' => 'discount_amount >= 0',
                'invoice_items_gst_rate_range' => 'gst_rate >= 0 AND gst_rate <= 100',
            ] as $name => $expression) {
                DB::statement("ALTER TABLE invoice_items ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
