<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only stock ledger. ARCHITECTURE-V1.md section 4.7.
 *
 * `products.current_stock` is a CACHE of SUM(stock_movements.quantity). This
 * table is the authority, so `quantity` is SIGNED -- positive in, negative out
 * -- which is what makes that sum meaningful and lets
 * `inventory:reconcile` prove the cache has not drifted.
 *
 * Immutable: no updated_at, no deleted_at. A correction is a new compensating
 * row, never an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            // RESTRICT: the ledger must never be orphaned, and a soft-deleted
            // product still owns its history.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // opening_stock | stock_in | stock_out | adjustment_in |
            // adjustment_out | invoice_sale | invoice_cancel
            $table->string('type', 32);

            $table->decimal('quantity', 12, 3);
            $table->decimal('previous_stock', 12, 3);
            $table->decimal('new_stock', 12, 3);

            // Polymorphic, deliberately NOT a real FK: the referenced row may
            // be soft-deleted and the ledger must survive it.
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->string('note', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Business date, which may differ from the insert time.
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
            $table->index('occurred_at');

            /*
             * THE idempotency guarantee.
             *
             * A sale movement references its INVOICE ITEM, not the invoice, so
             * an invoice may legitimately carry the same product on two lines
             * without colliding. With this index in place a second finalization
             * of the same invoice cannot deduct stock twice -- it is physically
             * impossible, not merely guarded by application care.
             */
            $table->unique(['type', 'reference_type', 'reference_id'], 'stock_movements_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
