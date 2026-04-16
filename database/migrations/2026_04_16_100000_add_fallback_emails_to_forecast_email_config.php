<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_email_config', function (Blueprint $t) {
            // Emails adicionales (siempre en copia) para cada envío —
            // aplica tanto al cron como al email de prueba.
            $t->json('fallback_emails')->nullable()->after('send_hour');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_email_config', function (Blueprint $t) {
            $t->dropColumn('fallback_emails');
        });
    }
};
