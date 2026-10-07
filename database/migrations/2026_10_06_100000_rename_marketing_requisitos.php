<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Requisitos de Marketing: "Pirámide Olfativa" pasa a "Diagramación de pirámides",
 * "Dummie Digital" a "Render digital", se retira "Investigación De Mercado" de las
 * opciones y se agrega "Etiquetas para aplicación".
 *
 * Solo se renombran los valores EXACTOS que ofrecía la pantalla. Los valores viejos
 * importados con otra escritura (p. ej. "Dummie digital") y los proyectos que ya
 * tenían "Investigación De Mercado" no se tocan. Los tiempos de la nueva opción
 * arrancan con los mismos valores de "Caja" y se ajustan desde la pantalla de tiempos.
 */
return new class extends Migration
{
    private const RENOMBRES = [
        'Pirámide Olfativa' => 'Diagramación de pirámides',
        'Dummie Digital'    => 'Render digital',
    ];

    public function up(): void
    {
        $this->renombrarEnProyectos(self::RENOMBRES);

        foreach (self::RENOMBRES as $viejo => $nuevo) {
            DB::table('time_marketing')->where('solicitud', $viejo)->update(['solicitud' => $nuevo]);
        }
        DB::table('time_marketing')->where('solicitud', 'Investigación De Mercado')->delete();

        if (!DB::table('time_marketing')->where('solicitud', 'Etiquetas para aplicación')->exists()) {
            $ahora = now();
            $filas = DB::table('time_marketing')->where('solicitud', 'Caja')->get(['grupo', 'valor'])
                ->map(fn ($f) => ['solicitud' => 'Etiquetas para aplicación', 'grupo' => $f->grupo, 'valor' => $f->valor,
                                  'created_at' => $ahora, 'updated_at' => $ahora])
                ->all();
            if ($filas) {
                DB::table('time_marketing')->insert($filas);
            }
        }
    }

    public function down(): void
    {
        $this->renombrarEnProyectos(array_flip(self::RENOMBRES));

        foreach (self::RENOMBRES as $viejo => $nuevo) {
            DB::table('time_marketing')->where('solicitud', $nuevo)->update(['solicitud' => $viejo]);
        }
        DB::table('time_marketing')->where('solicitud', 'Etiquetas para aplicación')->delete();
        // Las filas de "Investigación De Mercado" que se borraron no se restauran.
    }

    /** @param array<string,string> $mapa */
    private function renombrarEnProyectos(array $mapa): void
    {
        DB::table('project_marketing')->whereNotNull('marketing')->orderBy('id')->each(function ($fila) use ($mapa) {
            $valores = json_decode($fila->marketing, true);
            if (!is_array($valores) || !array_intersect($valores, array_keys($mapa))) {
                return;
            }
            $nuevos = array_values(array_unique(array_map(fn ($v) => $mapa[$v] ?? $v, $valores)));
            DB::table('project_marketing')->where('id', $fila->id)
                ->update(['marketing' => json_encode($nuevos, JSON_UNESCAPED_UNICODE)]);
        });
    }
};
