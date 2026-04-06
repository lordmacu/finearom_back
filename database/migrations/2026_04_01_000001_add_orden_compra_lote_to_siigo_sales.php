<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siigo_sales', function (Blueprint $table) {
            $table->string('orden_compra', 50)->nullable()->after('cuenta');
            $table->string('lote', 50)->nullable()->after('orden_compra');
        });
    }

    public function down(): void
    {
        Schema::table('siigo_sales', function (Blueprint $table) {
            $table->dropColumn(['orden_compra', 'lote']);
        });
    }
};
