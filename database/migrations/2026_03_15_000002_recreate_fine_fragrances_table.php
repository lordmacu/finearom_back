<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fine_fragrances');

        Schema::create('fine_fragrances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fine_fragrance_house_id')
                ->constrained('fine_fragrance_houses')
                ->cascadeOnDelete();
            $table->string('contratipo');
            $table->year('ano_lanzamiento')->nullable();
            $table->year('ano_desarrollo')->nullable();
            $table->enum('genero', ['masculino', 'femenino', 'unisex'])->nullable();
            $table->string('familia_olfativa')->nullable();
            $table->string('nombre')->nullable();
            $table->enum('tipo', ['305', '310', '350']);
            $table->text('salida')->nullable();
            $table->text('corazon')->nullable();
            $table->text('fondo')->nullable();
            $table->decimal('precio_coleccion', 10, 2)->nullable();
            $table->decimal('costo', 10, 2)->nullable();
            $table->decimal('inventario_kg', 10, 3)->default(0);
            $table->decimal('precio_oferta', 10, 2)->nullable();
            $table->enum('estado', ['activa', 'inactiva', 'novedad', 'saldo'])->default('activa');
            $table->string('foto_url')->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('fine_fragrance_house_id');
            $table->index('estado');
            $table->index('tipo');
            $table->unique(['fine_fragrance_house_id', 'contratipo', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_fragrances');

        Schema::create('fine_fragrances', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->nullable();
            $table->decimal('precio', 10, 2)->default(0);
            $table->decimal('precio_usd', 10, 2)->default(0);
            $table->foreignId('casa_id')
                ->nullable()
                ->constrained('fragrance_houses')
                ->nullOnDelete();
            $table->foreignId('family_id')
                ->nullable()
                ->constrained('fragrance_families')
                ->nullOnDelete();
            $table->timestamps();
        });
    }
};
