<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings for dealer invoicing and tax-inclusive pricing.
 *
 * `dealer_invoice_prefix` gives dealer invoices their own numbered series.
 * Both prefixes are capped at 2 characters by validation, because the number
 * format is {prefix}/{2026-27}/{00001} and GST allows at most 16 characters:
 * a 3-character prefix produces 17 and the generator refuses it at allocation
 * time.
 *
 * `prices_include_tax` is the DEFAULT for new invoices only. Each invoice
 * snapshots the value it was raised under, so changing this never rewrites the
 * arithmetic of an invoice already issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('dealer_invoice_prefix', 12)->default('JD')->after('invoice_prefix');

            // Defaults to false so nothing about existing behaviour changes
            // until the shop explicitly switches to MRP-inclusive pricing.
            $table->boolean('prices_include_tax')->default(false)->after('enable_round_off');

            // Printed in the declaration block of every invoice.
            $table->string('invoice_declaration', 500)->nullable()->after('invoice_terms');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn(['dealer_invoice_prefix', 'prices_include_tax', 'invoice_declaration']);
        });
    }
};
