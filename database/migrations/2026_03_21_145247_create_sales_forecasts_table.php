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
        Schema::create('sales_forecasts', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 50);
            $table->string('codigo', 50);
            $table->string('modelo', 30); // holt_winters, croston, theta, xgboost
            $table->string('año', 4);
            $table->string('mes', 20);
            $table->bigInteger('venta_forecast')->default(0);
            $table->bigInteger('lower_bound')->nullable();
            $table->bigInteger('upper_bound')->nullable();
            $table->enum('confianza', ['alta', 'media', 'baja'])->default('media');
            $table->timestamp('generated_at')->useCurrent();

            $table->index(['nit', 'codigo']);
            $table->index('modelo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_forecasts');
    }
};
