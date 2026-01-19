<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_order_product', function (Blueprint $table) {
            // Cambiar la columna price a decimal(10, 2)
            $table->decimal('price', 10, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_product', function (Blueprint $table) {
            // Revertir a integer (aunque esto podría perder datos, es el estado anterior)
            $table->integer('price')->change();
        });
    }
};
