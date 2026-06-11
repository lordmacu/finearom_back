<?php

use App\Services\EmailTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el nombre de la ejecutiva al asunto del reporte de pronósticos.
     * Así cada correo tiene un asunto único y no se agrupan en un mismo hilo
     * (sobre todo en la bandeja de quien va en CC, que recibe una copia por
     * cada ejecutiva).
     */
    public function up(): void
    {
        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update([
                'subject'    => '📊 Pronóstico semana |semana| — |mes| |año| — |ejecutiva|',
                'updated_at' => now(),
            ]);

        EmailTemplateService::clearCache('forecast_weekly');
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update([
                'subject'    => '📊 Pronóstico semana |semana| — |mes| |año|',
                'updated_at' => now(),
            ]);

        EmailTemplateService::clearCache('forecast_weekly');
    }
};
