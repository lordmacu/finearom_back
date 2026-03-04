<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_forecast_client_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cliente_id');
            $table->string('status', 40)->default('QUEUED');
            $table->unsignedInteger('total_productos')->default(0);
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('completados')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->unsignedInteger('pendientes')->default(0);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'status']);
            $table->index(['cliente_id', 'created_at']);
        });

        Schema::create('ia_forecast_client_run_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('cliente_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('codigo', 100)->nullable();
            $table->string('producto', 255)->nullable();
            $table->decimal('kg_total', 12, 1)->default(0);
            $table->string('status', 40)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('analizado_en')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'producto_id'], 'ia_forecast_run_item_unique');
            $table->index(['run_id', 'status']);
            $table->index(['cliente_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_forecast_client_run_items');
        Schema::dropIfExists('ia_forecast_client_runs');
    }
};
