<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consignee IS the buyer, so stop storing them twice.
 *
 * J Popular delivers to the dealer who bought the goods -- ship-to and bill-to
 * are the same party on every invoice. Keeping a second copy of that party did
 * not make the document more accurate, it made it possible for the two halves
 * to disagree: the buyer block reads the customer record, while the consignee
 * block read a stale copy nobody revisits.
 *
 * Production bore this out before the change. The only invoice carrying a
 * consignee named a different company from its buyer, yet both sides shared one
 * GSTIN -- the same legal entity, keyed twice under two spellings.
 *
 * Both blocks now render from the customer, so the invoice cannot contradict
 * itself. A genuine drop-ship would need these columns back, but it would need
 * a consignee address on the e-Way Bill page and a place-of-supply rule to go
 * with it, neither of which exists; a dormant column is not that feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'default_consignee_name',
                'default_consignee_address',
                'default_consignee_gstin',
                'default_consignee_state_code',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'consignee_name',
                'consignee_address',
                'consignee_gstin',
                'consignee_state_code',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('default_consignee_name', 160)->nullable();
            $table->string('default_consignee_address', 300)->nullable();
            $table->string('default_consignee_gstin', 15)->nullable();
            $table->char('default_consignee_state_code', 2)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('consignee_name', 160)->nullable();
            $table->string('consignee_address', 300)->nullable();
            $table->string('consignee_gstin', 15)->nullable();
            $table->char('consignee_state_code', 2)->nullable();
        });
    }
};
