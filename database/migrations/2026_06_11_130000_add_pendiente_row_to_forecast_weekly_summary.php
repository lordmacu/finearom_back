<?php

use App\Services\EmailTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Agrega la fila "Pendiente por facturar" al resumen del correo de
     * pronósticos. El cumplimiento se calcula como (facturado + pendiente) /
     * pronóstico, pero el resumen solo mostraba el facturado, lo que hacía
     * parecer mal calculado el porcentaje. Aquí mostramos el sumando que faltaba.
     *
     * De paso:
     *  - corrige el "kg" duplicado tras el valor en USD (|var| kg → |var|),
     *    porque la variable ya trae "X kg / $Y".
     *  - renombra "Total despachado" → "Facturado" (lenguaje del área comercial).
     *
     * Lee el header_content ACTUAL de la BD y hace reemplazos puntuales para no
     * pisar ninguna edición hecha desde el editor de plantillas.
     */
    public function up(): void
    {
        $tpl = DB::table('email_templates')->where('key', 'forecast_weekly')->first();
        if (!$tpl) {
            Log::warning('add_pendiente_row: no existe el template forecast_weekly — nada que hacer.');
            return;
        }

        $h = (string) $tpl->header_content;

        // Idempotente: si ya está la fila, no volver a tocar.
        if (str_contains($h, 'Pendiente por facturar')) {
            return;
        }

        // 1. Quitar el " kg" sobrante (la variable ya incluye unidades).
        $h = str_replace('|total_pronostico| kg', '|total_pronostico|', $h);
        $h = str_replace('|total_vendido| kg', '|total_vendido|', $h);

        // 2. Renombrar "Total despachado" → "Facturado".
        $h = str_replace('>Total despachado<', '>Facturado<', $h);

        // 3. Insertar la fila "Pendiente por facturar" justo antes de
        //    "Cumplimiento general".
        $pendRow = '  <tr>' . "\n"
            . '    <td style="padding:12px 16px;font-size:13px;color:#64748b;">Pendiente por facturar</td>' . "\n"
            . '    <td style="padding:12px 16px;font-size:18px;font-weight:700;color:#b45309;text-align:right;">|total_pendiente|</td>' . "\n"
            . '  </tr>' . "\n";

        $pos = strpos($h, 'Cumplimiento general');
        if ($pos === false) {
            Log::warning('add_pendiente_row: no se encontró "Cumplimiento general" en el header — no se insertó la fila.');
            return;
        }

        // Retroceder al "<tr" que abre la fila de Cumplimiento e insertar antes.
        $trPos = strrpos(substr($h, 0, $pos), '<tr');
        if ($trPos === false) {
            Log::warning('add_pendiente_row: no se encontró el <tr de Cumplimiento — no se insertó la fila.');
            return;
        }

        $h = substr($h, 0, $trPos) . $pendRow . substr($h, $trPos);

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

        $h = (string) $tpl->header_content;

        // Quitar la fila "Pendiente por facturar" (con su <tr>...</tr>).
        $h = preg_replace(
            '#\s*<tr>\s*<td[^>]*>Pendiente por facturar</td>.*?</tr>#s',
            '',
            $h
        );

        // Revertir nombre y unidades.
        $h = str_replace('>Facturado<', '>Total despachado<', $h);
        $h = str_replace('|total_pronostico|<', '|total_pronostico| kg<', $h);
        $h = str_replace('|total_vendido|<', '|total_vendido| kg<', $h);

        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update(['header_content' => $h, 'updated_at' => now()]);

        EmailTemplateService::clearCache('forecast_weekly');
    }
};
