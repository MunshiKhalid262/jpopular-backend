<?php

use App\Enums\InvoiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dealer invoicing: document type, consignee, transport and e-invoice fields.
 *
 * A dealer supply moves goods by road, so its invoice must carry what a driver
 * can be asked to produce -- e-Way Bill number, vehicle, destination -- while a
 * counter sale carries none of it.
 *
 * `prices_include_tax` is snapshotted PER INVOICE rather than read from
 * settings at print time. Existing invoices were raised tax-exclusive and must
 * keep reproducing their original figures however the shop prices things in
 * future; the column defaults to false precisely so every historical row keeps
 * the behaviour it was issued under.
 *
 * The e-invoice fields are entered by hand from the government portal. There is
 * no IRP or e-Way Bill API integration here, deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_type', 16)
                ->default(InvoiceType::Customer->value)
                ->after('invoice_number');

            // Historical accuracy: existing rows stay tax-exclusive.
            $table->boolean('prices_include_tax')->default(false)->after('tax_type');

            /*
             * Consignee. Denormalised on purpose, like the invoice item
             * snapshots: a dealer may be shipped to a site address that is
             * nothing to do with their billing address, and editing the
             * customer record later must not rewrite where past goods went.
             */
            $table->string('consignee_name', 160)->nullable();
            $table->string('consignee_address', 300)->nullable();
            $table->string('consignee_gstin', 15)->nullable();
            $table->char('consignee_state_code', 2)->nullable();

            // Transport and dispatch, printed on dealer invoices only.
            $table->string('eway_bill_no', 20)->nullable();
            $table->string('vehicle_no', 20)->nullable();
            $table->string('dispatched_through', 120)->nullable();
            $table->string('destination', 120)->nullable();
            $table->string('lr_rr_no', 60)->nullable();
            $table->date('lr_rr_date')->nullable();
            $table->string('delivery_note', 60)->nullable();
            $table->date('delivery_note_date')->nullable();
            $table->string('dispatch_doc_no', 60)->nullable();
            $table->string('buyer_order_no', 60)->nullable();
            $table->date('buyer_order_date')->nullable();
            $table->string('terms_of_delivery', 200)->nullable();
            $table->string('mode_of_payment', 120)->nullable();
            $table->string('other_references', 200)->nullable();

            // e-Invoice, entered manually from the IRP.
            $table->string('irn', 64)->nullable();
            $table->string('ack_no', 32)->nullable();
            $table->date('ack_date')->nullable();

            // Dealer and customer invoices run in separate series, so reports
            // and the numbering sequence both filter on this.
            $table->index(['invoice_type', 'invoice_date']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            /*
             * The price as entered. Under tax-inclusive pricing this is the
             * customer-facing figure and `unit_price` is the taxable rate
             * backed out of it; under tax-exclusive pricing the two match.
             * Stored rather than re-derived so the printed "Rate (Incl. of
             * Tax)" column is a snapshot like every other line value.
             */
            $table->decimal('unit_price_gross', 14, 2)->default(0)->after('unit_price');
        });

        /*
         * Additional charges: insurance, freight, handling.
         *
         * A separate table rather than more invoice columns, because a charge
         * carries its own SAC code and GST rate and must appear in the
         * HSN-wise tax summary as its own row -- insurance is an 18% service
         * on a 5% scooter sale.
         */
        Schema::create('invoice_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('description', 200);
            $table->string('hsn_code', 8)->nullable();

            $table->decimal('taxable_amount', 14, 2);
            $table->decimal('gst_rate', 5, 2)->default(0);

            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);

            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_charges');

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('unit_price_gross');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['invoice_type', 'invoice_date']);

            $table->dropColumn([
                'invoice_type',
                'prices_include_tax',
                'consignee_name',
                'consignee_address',
                'consignee_gstin',
                'consignee_state_code',
                'eway_bill_no',
                'vehicle_no',
                'dispatched_through',
                'destination',
                'lr_rr_no',
                'lr_rr_date',
                'delivery_note',
                'delivery_note_date',
                'dispatch_doc_no',
                'buyer_order_no',
                'buyer_order_date',
                'terms_of_delivery',
                'mode_of_payment',
                'other_references',
                'irn',
                'ack_no',
                'ack_date',
            ]);
        });
    }
};
