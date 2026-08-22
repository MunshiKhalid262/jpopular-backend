<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHITECTURE-V1.md section 4.6.
 *
 * Money is DECIMAL(14,2) and quantity DECIMAL(12,3) -- never float. `sku`
 * carries a plain unique index, so a soft-deleted product still occupies its
 * SKU: the strict policy from the architecture, since SKUs appear on
 * historical invoices.
 *
 * `current_stock` is the CACHED balance. It is written only by the Inventory
 * domain (StockLedger), never by the catalog API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // RESTRICT: a category in use must be deactivated, not removed.
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            $table->string('name', 200);
            $table->string('sku', 64)->unique();
            $table->string('model', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 16)->default('pcs');
            $table->string('hsn_code', 8)->nullable();

            $table->decimal('gst_rate', 5, 2)->default(18.00);
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('selling_price', 14, 2);

            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock_level', 12, 3)->default(0);

            $table->boolean('is_active')->default(true);
            $table->string('image_path', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('brand_id');
            $table->index('is_active');
            $table->index(['is_active', 'category_id']);
            $table->index('name');
            $table->index('hsn_code');
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }

    /**
     * Database-level backstops behind the application validation.
     *
     * MySQL only: SQLite (used by the test suite) cannot add table constraints
     * after CREATE TABLE, and the equivalent guarantees are covered there by
     * Form Request validation plus the StockLedger invariants.
     */
    private function addCheckConstraints(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $checks = [
            'products_selling_price_non_negative' => 'selling_price >= 0',
            'products_purchase_price_non_negative' => 'purchase_price IS NULL OR purchase_price >= 0',
            'products_gst_rate_range' => 'gst_rate >= 0 AND gst_rate <= 100',
            'products_current_stock_non_negative' => 'current_stock >= 0',
            'products_min_stock_level_non_negative' => 'min_stock_level >= 0',
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE products ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
