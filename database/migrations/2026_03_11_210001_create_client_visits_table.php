<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained('prospects')->nullOnDelete();
            $table->string('nombre_cliente', 300)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('titulo', 300);
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_fin')->nullable();
            $table->string('lugar', 300)->nullable();
            $table->enum('tipo', ['presencial', 'llamada', 'videollamada'])->default('presencial');
            $table->text('notas')->nullable();
            $table->string('google_event_id', 200)->nullable();
            $table->string('google_event_link', 500)->nullable();
            $table->enum('estado', ['programada', 'realizada', 'cancelada'])->default('programada');
            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
            $table->index('prospect_id');
            $table->index('user_id');
            $table->index('estado');
            $table->index('tipo');
            $table->index('fecha_inicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_visits');
    }
};
