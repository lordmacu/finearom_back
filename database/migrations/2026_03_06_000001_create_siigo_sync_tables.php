<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clientes sincronizados desde Siigo
        Schema::create('siigo_clients', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 20)->unique();
            $table->string('nombre', 100)->nullable();
            $table->string('tipo_doc', 5)->nullable();
            $table->string('numero_doc', 20)->nullable();
            $table->string('direccion', 120)->nullable();
            $table->string('ciudad', 60)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('tipo_tercero', 5)->nullable();
            $table->string('siigo_codigo', 20)->nullable();
            $table->string('siigo_hash', 32)->nullable();
            $table->timestamps();
        });

        // Productos sincronizados desde Siigo
        Schema::create('siigo_products', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 120)->nullable();
            $table->decimal('precio', 15, 2)->default(0);
            $table->string('unidad_medida', 10)->nullable();
            $table->string('grupo', 30)->nullable();
            $table->string('siigo_hash', 32)->nullable();
            $table->timestamps();
        });

        // Movimientos/transacciones sincronizados desde Siigo
        Schema::create('siigo_movements', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_comprobante', 10)->nullable();
            $table->string('numero_doc', 20)->nullable();
            $table->date('fecha')->nullable();
            $table->string('nit_tercero', 20)->nullable();
            $table->string('cuenta_contable', 20)->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->decimal('valor', 15, 2)->default(0);
            $table->string('tipo_mov', 2)->nullable(); // D=debito, C=credito
            $table->string('siigo_hash', 32)->nullable();
            $table->timestamps();

            $table->index(['nit_tercero', 'fecha']);
            $table->index('tipo_comprobante');
        });

        // Log de sincronizaciones
        Schema::create('siigo_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 20); // Z17, Z06, Z49
            $table->string('action', 20); // new, updated, deleted
            $table->integer('records_count')->default(0);
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siigo_sync_logs');
        Schema::dropIfExists('siigo_movements');
        Schema::dropIfExists('siigo_products');
        Schema::dropIfExists('siigo_clients');
    }
};
