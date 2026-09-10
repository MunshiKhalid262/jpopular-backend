<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHITECTURE-V1.md section 4.13.
 *
 * Invoice numbers must NOT come from MAX(invoice_number) + 1 or COUNT(*) + 1:
 * under two concurrent finalizations both read the same value and produce a
 * duplicate. A locked counter row serialises allocation instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            // 'invoice' today; 'credit_note' and 'fuel_sale' later.
            $table->string('type', 32);
            $table->string('prefix', 12);
            // e.g. 2026-27. Numbering restarts each financial year.
            $table->char('financial_year', 7);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['type', 'prefix', 'financial_year'], 'document_sequences_series_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
