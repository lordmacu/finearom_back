<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cartera sincronizada desde Siigo (Z09)
        Schema::create('siigo_cartera', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_registro', 5)->nullable();
            $table->string('nit_tercero', 20)->nullable();
            $table->string('cuenta_contable', 20)->nullable();
            $table->date('fecha')->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->string('tipo_mov', 2)->nullable(); // D=debito, C=credito
            $table->string('siigo_hash', 64)->nullable();
            $table->timestamps();

            $table->index('nit_tercero');
            $table->index('fecha');
            $table->index('siigo_hash');
        });

        // Webhook log: registro de webhooks recibidos
        Schema::create('siigo_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 50);
            $table->string('source_ip', 45)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received'); // received, processed, error
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('event');
            $table->index('created_at');
        });

        // Ampliar siigo_hash en tablas existentes de 32 a 64 chars (SHA256 = 64 hex)
        Schema::table('siigo_clients', function (Blueprint $table) {
            $table->string('siigo_hash', 64)->nullable()->change();
        });
        Schema::table('siigo_products', function (Blueprint $table) {
            $table->string('siigo_hash', 64)->nullable()->change();
        });
        Schema::table('siigo_movements', function (Blueprint $table) {
            $table->string('siigo_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siigo_webhook_logs');
        Schema::dropIfExists('siigo_cartera');
    }
};
