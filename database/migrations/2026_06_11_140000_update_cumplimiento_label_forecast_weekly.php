<?php

use App\Services\EmailTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const VIEJO = '>Cumplimiento general</td>';
    private const NUEVO = '>Cumplimiento General (total OC ingresadas a la fecha)</td>';

    /**
     * Aclara la etiqueta "Cumplimiento general" en el resumen del correo de
     * pronósticos para dejar explícito que el % refleja el total de órdenes de
     * compra ingresadas a la fecha (facturado + pendiente por facturar).
     */
    public function up(): void
    {
        $tpl = DB::table('email_templates')->where('key', 'forecast_weekly')->first();
        if (!$tpl) {
            Log::warning('update_cumplimiento_label: no existe el template forecast_weekly.');
            return;
        }

        $h = (string) $tpl->header_content;

        if (str_contains($h, self::NUEVO)) {
            return; // ya aplicado
        }
        if (!str_contains($h, self::VIEJO)) {
            Log::warning('update_cumplimiento_label: no se encontró la etiqueta esperada — no se cambió nada.');
            return;
        }

        $h = str_replace(self::VIEJO, self::NUEVO, $h);

        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update(['header_content' => $h, 'updated_at' => now()]);

        EmailTemplateService::clearCache('forecast_weekly');
    }

    public function down(): void
    {
        $tpl = DB::table('email_templates')->where('key', 'forecast_weekly')->first();
        if (!$tpl) {
            return;
        }

        $h = str_replace(self::NUEVO, self::VIEJO, (string) $tpl->header_content);

        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update(['header_content' => $h, 'updated_at' => now()]);

        EmailTemplateService::clearCache('forecast_weekly');
    }
};
