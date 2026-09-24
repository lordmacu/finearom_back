<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las variantes de Desarrollo que ya existían (antes de la sincronización
 * automática) también quedan en Marketing. Si Marketing ya tiene en ese
 * proyecto una variante sin enlazar con el mismo nombre, se enlaza en vez
 * de duplicarla. Idempotente: solo toca variantes de Desarrollo sin enlace.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El nombre de Marketing (200) era más corto que el de Desarrollo (255):
        // se amplía para copiar los nombres completos
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE project_marketing_variants MODIFY nombre VARCHAR(255) NULL');
        }

        $normalizar = fn (?string $nombre) => mb_strtolower(trim((string) $nombre));

        DB::table('project_variants')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('project_marketing_variants as mv')
                ->whereColumn('mv.project_variant_id', 'project_variants.id'))
            // chunkById pagina por id: no agregar otro orderBy o se salta lotes
            ->chunkById(500, function ($variantes) use ($normalizar) {
                $porProyecto = $variantes->groupBy('project_id');

                // Variantes de Marketing sin enlazar y el mayor "orden", por proyecto
                $existentes = DB::table('project_marketing_variants')
                    ->whereIn('project_id', $porProyecto->keys())
                    ->get(['id', 'project_id', 'project_variant_id', 'nombre', 'orden'])
                    ->groupBy('project_id');

                $ahora = now();
                $nuevas = [];

                foreach ($porProyecto as $projectId => $delProyecto) {
                    $mvs   = $existentes->get($projectId, collect());
                    $orden = (int) $mvs->max('orden');
                    $libres = $mvs->whereNull('project_variant_id')->keyBy(fn ($mv) => $normalizar($mv->nombre));

                    foreach ($delProyecto as $v) {
                        $clave = $normalizar($v->nombre);
                        if ($clave !== '' && $libres->has($clave)) {
                            DB::table('project_marketing_variants')
                                ->where('id', $libres[$clave]->id)
                                ->update(['project_variant_id' => $v->id, 'updated_at' => $ahora]);
                            $libres->forget($clave);
                            continue;
                        }

                        $nuevas[] = [
                            'project_id'         => $projectId,
                            'project_variant_id' => $v->id,
                            'nombre'             => $v->nombre,
                            'orden'              => ++$orden,
                            'created_at'         => $ahora,
                            'updated_at'         => $ahora,
                        ];
                    }
                }

                foreach (array_chunk($nuevas, 500) as $lote) {
                    DB::table('project_marketing_variants')->insert($lote);
                }
            });
    }

    public function down(): void
    {
        // Relleno de datos: no se deshace (no hay forma segura de distinguir
        // estas variantes de las que Marketing ya trabajó después).
    }
};
