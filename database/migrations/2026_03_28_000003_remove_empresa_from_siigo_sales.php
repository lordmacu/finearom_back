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
            $table->dropColumn('empresa');
            $table->unique(['nit', 'product_code', 'cuenta', 'mes'], 'siigo_sales_unique');
        });
    }

    public function down(): void
    {
        Schema::table('siigo_sales', function (Blueprint $table) {
            $table->dropUnique('siigo_sales_unique');
            $table->string('empresa', 10)->default('')->after('nit');
            $table->unique(['nit', 'product_code', 'empresa', 'cuenta', 'mes'], 'siigo_sales_unique');
        });
    }
};
