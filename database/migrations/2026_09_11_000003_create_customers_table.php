<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHITECTURE-V1.md section 4.8.
 *
 * GSTIN is nullable on purpose: most buyers are retail walk-ins. The billing
 * mode is chosen explicitly per invoice and is never inferred from whether a
 * customer happens to have a GSTIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 160);
            $table->string('phone', 20)->nullable();
            $table->string('email', 160)->nullable();

            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            // The buyer side of the intra/inter-state decision. Required before
            // a GST invoice can be finalized.
            $table->char('state_code', 2)->nullable();
            $table->string('pincode', 10)->nullable();

            $table->char('gstin', 15)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Phone is the practical lookup key at a counter. Unique so the
            // same walk-in is not entered twice, but nullable for customers
            // who decline to give one.
            $table->unique('phone');
            $table->index('name');
            $table->index('gstin');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
