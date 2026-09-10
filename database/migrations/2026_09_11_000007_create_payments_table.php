<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHITECTURE-V1.md section 4.11: a separate table rather than payment fields
 * on the invoice.
 *
 * Scooter sales are exactly where split payments happen ("5,000 UPI now,
 * 45,000 on delivery"), and per-payment method plus reference number is
 * impossible to record in a single set of invoice columns.
 *
 * `invoices.paid_amount` caches SUM(amount) of non-voided rows here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // RESTRICT: an invoice with money against it is never removed.
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            $table->decimal('amount', 14, 2);
            // cash | upi | card | bank_transfer | cheque | other
            $table->string('payment_method', 20);
            // UTR, transaction id or cheque number.
            $table->string('reference', 80)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->string('note', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Financial records are voided with a reason, never edited.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('invoice_id');
            $table->index('received_at');
            $table->index('payment_method');
            $table->index('voided_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
            DB::statement(
                "ALTER TABLE payments ADD CONSTRAINT payments_method_valid
                 CHECK (payment_method IN ('cash','upi','card','bank_transfer','cheque','other'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
