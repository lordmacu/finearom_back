<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_terminados', function (Blueprint $table) {
            $table->id();
            $table->string('consecutivo', 20)->nullable()->unique();
            $table->string('codigo', 100)->unique();
            $table->string('nombre');
            $table->decimal('costo_unitario', 12, 4)->default(0);
            $table->boolean('activo')->default(false);
            $table->timestamps();

            $table->index('codigo');
            $table->index('nombre');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_terminados');
    }
};
