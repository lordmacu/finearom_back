<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siigo_sales', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 50)->index();
            $table->string('empresa', 10)->nullable();
            $table->string('cuenta', 30)->nullable();
            $table->string('producto', 50)->index();
            $table->string('descripcion')->nullable();
            $table->decimal('precio_unitario', 15, 2)->default(0);
            $table->string('mes', 7); // formato YYYY-MM
            $table->decimal('valor', 15, 2)->default(0);
            $table->integer('cantidad')->default(0);
            $table->timestamps();

            $table->unique(['nit', 'producto', 'empresa', 'cuenta', 'mes'], 'siigo_sales_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siigo_sales');
    }
};
