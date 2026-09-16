<?php

use App\Domain\Catalog\Services\ProductImageStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF DATA RESET, requested before going live.
 *
 * Clears products and every transactional record that hangs off them, so the
 * shop starts from a clean slate instead of carrying demo data into real use.
 *
 * ------------------------------------------------------------------------
 * READ THIS BEFORE RE-RUNNING ANYTHING
 *
 * This migration DELETES DATA and `down()` cannot bring it back. Rows are not
 * recoverable from the migration itself -- only from a database backup taken
 * beforehand.
 *
 * It runs once per environment, like any migration. It will NOT re-run on
 * later deploys, because Laravel records it in the migrations table. But a
 * `migrate:fresh` or a brand-new environment WILL execute it again, which is
 * harmless on an empty database and destructive on a populated one.
 * ------------------------------------------------------------------------
 *
 * WHAT IS CLEARED: every business and transactional record -- products,
 * categories, brands, customers, invoices and their items, charges and
 * payments, the stock ledger, the invoice numbering sequences, and the
 * product image files on the public disk.
 *
 * WHAT IS KEPT, deliberately:
 *   - users, roles and permissions, or nobody could log in afterwards;
 *   - business_settings, which holds the GSTIN, address and invoice prefixes
 *     that were just configured and would have to be retyped.
 *
 * Deletion order is dictated by the foreign keys, not by preference: payments
 * and invoice children before invoices, stock movements before products, and
 * products before the categories and brands they point at -- those sides are
 * RESTRICT on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Children first: every one of these is RESTRICT or CASCADE on a
            // parent we are about to remove.
            $this->truncateIfExists('payments');
            $this->truncateIfExists('invoice_charges');
            $this->truncateIfExists('invoice_items');

            // Soft-deleted invoices are rows too, so this is a hard delete.
            $this->truncateIfExists('invoices');

            // The ledger references products with RESTRICT, so it goes first.
            $this->truncateIfExists('stock_movements');

            // Invoices referenced customers with RESTRICT; they are gone now.
            $this->truncateIfExists('customers');

            // Products reference categories and brands with RESTRICT and
            // SET NULL respectively, so products go before both.
            $this->truncateIfExists('products');
            $this->truncateIfExists('brands');
            $this->truncateIfExists('categories');

            /*
             * Reset the invoice numbering. Without this the first real invoice
             * would continue from wherever the demo data left off -- a shop
             * issuing JP/2026-27/00009 as its first ever bill invites exactly
             * the question about missing numbers that a GST audit asks.
             */
            $this->truncateIfExists('document_sequences');
        });

        // Outside the transaction: filesystem work cannot be rolled back, so
        // it runs only once the row deletions have actually committed.
        $this->deleteProductImages();
    }

    /**
     * Removes the product image files whose rows have just been deleted.
     *
     * Delegated to ProductImageStore, the only class permitted to write these
     * files and the only one that knows the managed directory.
     */
    private function deleteProductImages(): void
    {
        app(ProductImageStore::class)->purgeAll();
    }

    /**
     * Deletes every row, including soft-deleted ones.
     *
     * DELETE rather than TRUNCATE: truncate cannot run inside a transaction on
     * MySQL (it commits implicitly) and is refused outright while foreign keys
     * point at the table. A plain delete respects both.
     */
    private function truncateIfExists(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }

    public function down(): void
    {
        /*
         * Deliberately empty and deliberately not throwing.
         *
         * Deleted rows cannot be reconstructed, so there is nothing honest to
         * do here. Rolling back leaves the tables empty; restore from the
         * backup taken before this ran.
         */
    }
};
