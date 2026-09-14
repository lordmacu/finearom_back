<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hilo de correo interno del proyecto: Message-ID y asunto del correo raíz.
     * Los correos siguientes responden con In-Reply-To/References y "Re: <asunto>".
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('email_thread_message_id', 255)->nullable();
            $table->string('email_thread_subject', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['email_thread_message_id', 'email_thread_subject']);
        });
    }
};
