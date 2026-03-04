<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_forecast_batch_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 40)->default('PROCESS_ALL');
            $table->string('status', 40)->default('QUEUED');
            $table->unsignedInteger('total_clientes')->default(0);
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

            $table->index(['status', 'created_at']);
        });

        Schema::create('ia_forecast_batch_run_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_run_id');
            $table->unsignedBigInteger('cliente_id');
            $table->string('client_name', 255)->nullable();
            $table->unsignedInteger('total_productos')->default(0);
            $table->unsignedBigInteger('current_run_id')->nullable();
            $table->string('status', 40)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['batch_run_id', 'cliente_id'], 'ia_forecast_batch_item_unique');
            $table->index(['batch_run_id', 'status']);
        });

        Schema::table('ia_forecast_client_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_run_id')->nullable()->after('cliente_id');
            $table->index(['batch_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ia_forecast_client_runs', function (Blueprint $table) {
            $table->dropIndex(['batch_run_id', 'status']);
            $table->dropColumn('batch_run_id');
        });

        Schema::dropIfExists('ia_forecast_batch_run_items');
        Schema::dropIfExists('ia_forecast_batch_runs');
    }
};
