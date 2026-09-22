<?php

use App\Enums\CustomerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dealers, as a kind of customer rather than a separate entity.
 *
 * A dealer has the same billing identity a customer has, and invoices already
 * point at customers with a RESTRICT foreign key. Adding a type here keeps
 * that link intact, avoids a second name/GSTIN/address implementation, and
 * means the GST supply-type resolver does not have to learn about two sorts
 * of buyer.
 *
 * The `default_*` columns are what a dealer actually adds: the dispatch
 * details that repeat on every supply to them. They are DEFAULTS, copied onto
 * an invoice when the dealer is chosen and editable there afterwards -- the
 * invoice keeps its own snapshot, so correcting a dealer's address later never
 * rewrites where past goods went.
 *
 * Deliberately NOT defaulted: e-Way Bill number, vehicle number, LR-RR,
 * delivery note, dispatch doc, buyer's order and the e-invoice fields. Those
 * differ on every trip, and pre-filling them would put last week's lorry on
 * this week's invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('type', 16)
                ->default(CustomerType::Customer->value)
                ->after('id');

            // Dealer dispatch defaults.
            $table->string('default_consignee_name', 160)->nullable();
            $table->string('default_consignee_address', 300)->nullable();
            $table->string('default_consignee_gstin', 15)->nullable();
            $table->char('default_consignee_state_code', 2)->nullable();
            $table->string('default_dispatched_through', 120)->nullable();
            $table->string('default_destination', 120)->nullable();
            $table->string('default_terms_of_delivery', 200)->nullable();
            $table->string('default_mode_of_payment', 120)->nullable();

            // The dealer list and the invoice form both filter on this.
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['type', 'is_active']);

            $table->dropColumn([
                'type',
                'default_consignee_name',
                'default_consignee_address',
                'default_consignee_gstin',
                'default_consignee_state_code',
                'default_dispatched_through',
                'default_destination',
                'default_terms_of_delivery',
                'default_mode_of_payment',
            ]);
        });
    }
};
