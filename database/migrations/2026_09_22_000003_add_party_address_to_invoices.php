<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address as printed on THIS invoice.
 *
 * Prefilled from the customer when they are chosen and editable afterwards: a
 * dealer may take delivery at a different site this week, or the address may
 * need a correction on one document without rewriting the dealer record.
 *
 * Nullable, and the document falls back to the customer's address when it is
 * empty. Existing invoices are therefore untouched -- they keep printing
 * exactly what they printed before this column existed.
 *
 * Deliberately address only. Name, GSTIN and state code are identity and
 * tax-critical: the state code decides CGST+SGST vs IGST, so letting it be
 * retyped per invoice would let the printed document disagree with the tax
 * that was actually charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('party_address', 300)->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('party_address');
        });
    }
};
