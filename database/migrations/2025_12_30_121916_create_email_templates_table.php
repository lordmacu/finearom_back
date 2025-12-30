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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Identificador único del template (ej: client_welcome, purchase_order_update)');
            $table->string('name')->comment('Nombre descriptivo del template');
            $table->string('subject')->comment('Asunto del email');
            $table->string('title')->nullable()->comment('Título con estilo especial que aparece en el email');
            $table->text('header_content')->nullable()->comment('Contenido HTML del header (editable con CKEditor)');
            $table->text('footer_content')->nullable()->comment('Contenido HTML del footer (editable con CKEditor)');
            $table->text('signature')->nullable()->comment('Firma HTML (editable con CKEditor)');
            $table->text('available_variables')->nullable()->comment('JSON con las variables disponibles para este template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
