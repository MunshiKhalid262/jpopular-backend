<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product codes no longer have to be unique.
 *
 * The same manufacturer code covers variants the shop stocks as separate
 * products -- the same model in another colour or battery -- and refusing the
 * second one forced the operator to invent a code the supplier does not use,
 * which then has to be translated back by hand on every purchase order.
 *
 * Safe to relax because nothing resolves a product BY its code. Every lookup
 * goes through the product id, and an invoice line keeps its own snapshot of
 * the code. The column's remaining jobs are display and search.
 *
 * The index stays, as a plain one: the product list searches the code with
 * LIKE, and that wants an index whether or not the values are distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->unique('sku');
        });
    }
};
