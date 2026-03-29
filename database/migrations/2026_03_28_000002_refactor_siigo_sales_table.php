<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siigo_sales', function (Blueprint $table) {
            $table->dropUnique('siigo_sales_unique');
            $table->dropIndex('siigo_sales_producto_index');
            $table->dropColumn(['producto', 'descripcion']);
            $table->string('product_code', 30)->nullable()->after('cuenta')->index();
            $table->unique(['nit', 'product_code', 'empresa', 'cuenta', 'mes'], 'siigo_sales_unique');
        });
    }

    public function down(): void
    {
        Schema::table('siigo_sales', function (Blueprint $table) {
            $table->dropUnique('siigo_sales_unique');
            $table->dropIndex('siigo_sales_product_code_index');
            $table->dropColumn('product_code');
            $table->string('producto', 50)->after('cuenta')->index();
            $table->string('descripcion')->nullable()->after('producto');
            $table->unique(['nit', 'producto', 'empresa', 'cuenta', 'mes'], 'siigo_sales_unique');
        });
    }
};
