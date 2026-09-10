<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHITECTURE-V1.md section 4.9, plus `tax_type` for the GST / non-GST split.
 *
 * All money is DECIMAL(14,2) and all quantity DECIMAL(12,3) -- never float.
 *
 * `status` and `payment_status` are deliberately ORTHOGONAL: a cancelled
 * invoice that was partly paid needs both facts represented, and payment state
 * is derived from the payments table rather than commanded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // NULL while draft; allocated from document_sequences at
            // finalization. <= 16 chars per GST rules.
            $table->string('invoice_number', 16)->nullable()->unique();
            $table->char('financial_year', 7)->nullable();
            $table->date('invoice_date');

            // Nullable for a walk-in cash sale. RESTRICT so a customer with
            // invoice history cannot be removed.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();

            /*
             * The billing mode, chosen EXPLICITLY by the operator.
             *
             * Never inferred from whether the customer has a GSTIN: a
             * GSTIN-holding buyer may still be billed without GST, and a
             * retail buyer may need a GST invoice.
             */
            $table->string('tax_type', 16);

            $table->string('status', 16)->default('draft');
            $table->string('payment_status', 20)->default('unpaid');

            // Only meaningful for tax_type = gst; NULL on a non-GST bill.
            $table->string('supply_type', 16)->nullable();
            $table->char('place_of_supply_state_code', 2)->nullable();
            $table->char('seller_state_code', 2)->nullable();

            // --- money -------------------------------------------------
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);

            // Cached from the payments table by RecordPayment / VoidPayment.
            $table->decimal('paid_amount', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();
            // Present so Eloquent conventions hold, but a FINALIZED invoice is
            // never deleted -- it is cancelled, and keeps its number so the
            // GST series stays continuous. Only drafts may be soft-deleted.
            $table->softDeletes();

            $table->index('customer_id');
            $table->index(['status', 'invoice_date']);
            $table->index('invoice_date');
            $table->index('payment_status');
            $table->index('tax_type');
            $table->index('financial_year');
        });

        $this->addGeneratedColumnAndChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }

    /**
     * MySQL only: SQLite (the test database) cannot add table constraints
     * after CREATE TABLE, and the equivalent guarantees are covered there by
     * validation plus the invoice Actions.
     */
    private function addGeneratedColumnAndChecks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            // SQLite gets a plain column kept in step by the same code path
            // that maintains paid_amount.
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('due_amount', 14, 2)->default(0);
            });

            return;
        }

        // A STORED generated column cannot drift from grand_total - paid_amount,
        // and is indexable for the outstanding-payments query.
        DB::statement(
            'ALTER TABLE invoices ADD COLUMN due_amount DECIMAL(14,2)
             AS (grand_total - paid_amount) STORED'
        );
        DB::statement('ALTER TABLE invoices ADD INDEX invoices_due_amount_index (due_amount)');

        foreach ([
            'invoices_grand_total_non_negative' => 'grand_total >= 0',
            'invoices_paid_amount_non_negative' => 'paid_amount >= 0',
            'invoices_discount_non_negative' => 'discount_amount >= 0',
            'invoices_tax_non_negative' => 'total_tax >= 0',
            'invoices_tax_type_valid' => "tax_type IN ('gst','non_gst')",
            'invoices_status_valid' => "status IN ('draft','finalized','cancelled')",
            // A non-GST bill must carry no tax. Enforced by the database so no
            // future code path can quietly charge GST on one.
            'invoices_non_gst_has_no_tax' => "tax_type <> 'non_gst' OR (cgst_amount = 0 AND sgst_amount = 0 AND igst_amount = 0 AND total_tax = 0)",
            // CGST/SGST and IGST are mutually exclusive.
            'invoices_gst_split_exclusive' => '(cgst_amount = 0 AND sgst_amount = 0) OR igst_amount = 0',
        ] as $name => $expression) {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
