<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHITECTURE-V1.md section 4.12: a single-row typed table, not key-value and
 * not multi-tenant.
 *
 * Required by GST billing: the seller's `state_code` is what decides
 * CGST+SGST vs IGST, and `invoice_prefix` feeds the numbering sequence. There
 * is no way to raise a correct GST invoice without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();

            $table->string('business_name', 160);
            $table->string('legal_name', 160)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();

            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            // The seller side of the intra/inter-state decision.
            $table->char('state_code', 2)->nullable();
            $table->string('pincode', 10)->nullable();

            $table->string('phone', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('website', 160)->nullable();
            $table->string('logo_path', 255)->nullable();

            $table->string('invoice_prefix', 12)->default('JP');
            $table->text('invoice_terms')->nullable();

            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_name', 160)->nullable();
            $table->string('bank_account_number', 34)->nullable();
            $table->string('bank_ifsc', 11)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->string('upi_id', 100)->nullable();

            $table->decimal('default_gst_rate', 5, 2)->default(18.00);
            // Default billing mode for a new invoice; the operator still
            // chooses explicitly on the invoice screen.
            $table->string('default_tax_type', 16)->default('gst');
            $table->char('currency', 3)->default('INR');
            $table->boolean('enable_round_off')->default(true);
            $table->unsignedTinyInteger('financial_year_start_month')->default(4);

            // Single row, enforced by the database on EVERY driver.
            //
            // The obvious spelling, CHECK (id = 1), is not portable: MySQL
            // rejects a CHECK that refers to an AUTO_INCREMENT column with
            // error 3818, so that constraint could never be created here. A
            // constant column with a unique index gives the same guarantee --
            // the second insert always collides on the default value of 1 --
            // and it behaves identically on MySQL and SQLite, so the test
            // suite actually exercises it rather than skipping a MySQL-only
            // branch.
            $table->unsignedTinyInteger('singleton')->default(1);
            $table->unique('singleton', 'business_settings_single_row');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
