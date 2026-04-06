<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Insertar |mensaje_motivacional| entre la tabla de resumen y |tabla_detalle|
        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update([
                'header_content' => '
<p>Estimada <strong>|primer_nombre|</strong>,</p>
<p>Aquí tienes el resumen del cumplimiento de pronósticos de tus clientes para <strong>|mes| |año|</strong> — Semana |semana|.</p>

<table style="width:100%;border-collapse:collapse;margin:16px 0;background:#f0f9ff;border-radius:8px;overflow:hidden;">
  <tr>
    <td style="padding:12px 16px;font-size:13px;color:#64748b;">Pronóstico del mes</td>
    <td style="padding:12px 16px;font-size:18px;font-weight:700;color:#1e40af;text-align:right;">|total_pronostico| kg</td>
  </tr>
  <tr style="background:#fff;">
    <td style="padding:12px 16px;font-size:13px;color:#64748b;">Total despachado</td>
    <td style="padding:12px 16px;font-size:18px;font-weight:700;color:#059669;text-align:right;">|total_vendido| kg</td>
  </tr>
  <tr>
    <td style="padding:12px 16px;font-size:13px;color:#64748b;">Cumplimiento general</td>
    <td style="padding:12px 16px;font-size:18px;font-weight:700;color:#7c3aed;text-align:right;">|total_cumplimiento|</td>
  </tr>
</table>

|mensaje_motivacional|

|tabla_detalle|
',
                'available_variables' => json_encode([
                    'ejecutiva'            => 'Nombre completo de la ejecutiva',
                    'primer_nombre'        => 'Primer nombre de la ejecutiva',
                    'mes'                  => 'Mes del reporte (ej: Marzo)',
                    'año'                  => 'Año del reporte',
                    'semana'               => 'Número de semana del mes',
                    'total_pronostico'     => 'Total pronóstico en kg del mes',
                    'total_vendido'        => 'Total despachado en kg del mes',
                    'total_cumplimiento'   => '% de cumplimiento general',
                    'mensaje_motivacional' => 'Mensaje según cumplimiento (felicitación si ≥ 100%, motivación si < 100%)',
                    'tabla_detalle'        => 'Tabla HTML con detalle por cliente y producto',
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Revertir: quitar |mensaje_motivacional| del template
        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update([
                'header_content' => '
<p>Estimada <strong>|primer_nombre|</strong>,</p>
<p>Aquí tienes el resumen del cumplimiento de pronósticos de tus clientes para <strong>|mes| |año|</strong> — Semana |semana|.</p>

<table style="width:100%;border-collapse:collapse;margin:16px 0;background:#f0f9ff;border-radius:8px;overflow:hidden;">
  <tr>
    <td style="padding:12px 16px;font-size:13px;color:#64748b;">Pronóstico del mes</td>
    <td style="padding:12px 16px;font-size:18px;font-weight:700;color:#1e40af;text-align:right;">|total_pronostico| kg</td>
  </tr>
  <tr style="background:#fff;">
    <td style="padding:12px 16px;font-size:13px;color:#64748b;">Total despachado</td>
    <td style="padding:12px 16px;font-size:18px;font-weight:700;color:#059669;text-align:right;">|total_vendido| kg</td>
  </tr>
  <tr>
    <td style="padding:12px 16px;font-size:13px;color:#64748b;">Cumplimiento general</td>
    <td style="padding:12px 16px;font-size:18px;font-weight:700;color:#7c3aed;text-align:right;">|total_cumplimiento|</td>
  </tr>
</table>

|tabla_detalle|
',
                'updated_at' => now(),
            ]);
    }
};
