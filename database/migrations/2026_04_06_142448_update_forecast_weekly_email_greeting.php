<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        DB::table('email_templates')
            ->where('key', 'forecast_weekly')
            ->update([
                'header_content' => '
<p>Hola <strong>|ejecutiva|</strong>,</p>
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
