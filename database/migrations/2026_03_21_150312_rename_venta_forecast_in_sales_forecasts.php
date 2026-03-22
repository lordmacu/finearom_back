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
        Schema::table('sales_forecasts', function (Blueprint $table) {
            $table->renameColumn('venta_forecast', 'cantidad_forecast');
        });
    }

    public function down(): void
    {
        Schema::table('sales_forecasts', function (Blueprint $table) {
            $table->renameColumn('cantidad_forecast', 'venta_forecast');
        });
    }
};
