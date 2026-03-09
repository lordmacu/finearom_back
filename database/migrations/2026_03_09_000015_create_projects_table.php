<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->enum('tipo', ['Colección', 'Desarrollo', 'Fine Fragances']);
            $table->decimal('rango_min', 10, 2)->nullable();
            $table->decimal('rango_max', 10, 2)->nullable();
            $table->decimal('volumen', 10, 2)->nullable();
            $table->boolean('base_cliente')->default(false);
            $table->boolean('proactivo')->default(false);
            $table->date('fecha_requerida')->nullable();
            $table->date('fecha_creacion');
            $table->date('fecha_calculada')->nullable();
            $table->date('fecha_entrega')->nullable();
            $table->string('tipo_producto')->nullable();
            $table->decimal('trm', 10, 2)->nullable();
            $table->decimal('factor', 10, 4)->default(1);
            $table->boolean('homologacion')->default(false);
            $table->boolean('internacional')->default(false);
            $table->string('ejecutivo')->nullable();

            // Estado externo (comercial)
            $table->enum('estado_externo', ['En espera', 'Ganado', 'Perdido'])->default('En espera');
            $table->date('fecha_externo')->nullable();
            $table->string('ejecutivo_externo')->nullable();

            // Estado interno (workflow)
            $table->enum('estado_interno', ['En proceso', 'Entregado'])->default('En proceso');
            $table->string('ejecutivo_interno')->nullable();

            // Estados departamentales
            $table->boolean('estado_desarrollo')->default(false);
            $table->date('fecha_desarrollo')->nullable();
            $table->string('ejecutivo_desarrollo')->nullable();

            $table->boolean('estado_laboratorio')->default(false);
            $table->date('fecha_laboratorio')->nullable();
            $table->string('ejecutivo_laboratorio')->nullable();

            $table->boolean('estado_mercadeo')->default(false);
            $table->date('fecha_mercadeo')->nullable();
            $table->string('ejecutivo_mercadeo')->nullable();

            $table->boolean('estado_calidad')->default(false);
            $table->date('fecha_calidad')->nullable();
            $table->string('ejecutivo_calidad')->nullable();

            $table->boolean('estado_especiales')->default(false);
            $table->date('fecha_especiales')->nullable();
            $table->string('ejecutivo_especiales')->nullable();

            // Observaciones por área
            $table->text('obs_lab')->nullable();
            $table->text('obs_des')->nullable();
            $table->text('obs_mer')->nullable();
            $table->text('obs_cal')->nullable();
            $table->text('obs_esp')->nullable();
            $table->text('obs_ext')->nullable();

            $table->boolean('actualizado')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
