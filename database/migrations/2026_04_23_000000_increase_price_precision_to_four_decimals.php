<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->default(0)->change();
        });

        Schema::table('purchase_order_product', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->change();
        });

        Schema::table('product_price_history', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->default(0)->change();
        });

        Schema::table('purchase_order_product', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->change();
        });

        Schema::table('product_price_history', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->change();
        });
    }
};
