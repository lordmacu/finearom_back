<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de entregas por área (parciales, final y actualizaciones). Cada
     * entrega guarda sus notas y sus adjuntos (project_files.delivery_log_id).
     * Lo que ya existía (notas actuales + adjuntos del área) queda como la
     * primera entrada: final si el área ya estaba entregada, parcial si no.
     */
    private const AREAS = [
        // área => [tabla 1:1 con notas_entrega (null = project_area_deliveries), columna de estado]
        'aplicaciones' => ['project_applications', 'estado_laboratorio'],
        'evaluaciones' => ['project_evaluations', 'estado_evaluaciones'],
        'marketing'    => ['project_marketing', 'estado_mercadeo'],
        'regulatoria'  => [null, 'estado_calidad'],
        'especiales'   => [null, 'estado_especiales'],
    ];

    public function up(): void
    {
        Schema::create('project_area_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('area', 30);
            $table->string('tipo', 20); // parcial | final | actualizacion
            $table->longText('notas')->nullable();
            $table->string('ejecutivo')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'area']);
        });

        Schema::table('project_files', function (Blueprint $table) {
            $table->foreignId('delivery_log_id')
                ->nullable()
                ->after('categoria')
                ->constrained('project_area_delivery_logs')
                ->nullOnDelete();
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        foreach (self::AREAS as $area => [$tabla, $estado]) {
            $notas = $tabla
                ? DB::table($tabla)->whereNotNull('notas_entrega')->pluck('notas_entrega', 'project_id')
                : DB::table('project_area_deliveries')->where('area', $area)->whereNotNull('notas_entrega')->pluck('notas_entrega', 'project_id');

            $conArchivos = DB::table('project_files')->where('categoria', $area)->distinct()->pluck('project_id');

            $proyectos = DB::table('projects')
                ->whereIn('id', $notas->keys()->merge($conArchivos)->unique())
                ->get(['id', $estado, "fecha_" . str_replace('estado_', '', $estado) . ' as fecha', "ejecutivo_" . str_replace('estado_', '', $estado) . ' as quien']);

            foreach ($proyectos as $p) {
                $texto = $notas[$p->id] ?? null;
                if (trim(strip_tags((string) $texto)) === '' && !$conArchivos->contains($p->id)) {
                    continue;
                }

                $fecha = $p->fecha ? $p->fecha . ' 00:00:00' : now();
                $logId = DB::table('project_area_delivery_logs')->insertGetId([
                    'project_id' => $p->id,
                    'area'       => $area,
                    'tipo'       => $p->{$estado} ? 'final' : 'parcial',
                    'notas'      => $texto,
                    'ejecutivo'  => $p->quien,
                    'created_at' => $fecha,
                    'updated_at' => $fecha,
                ]);

                DB::table('project_files')
                    ->where('project_id', $p->id)
                    ->where('categoria', $area)
                    ->whereNull('delivery_log_id')
                    ->update(['delivery_log_id' => $logId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('project_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_log_id');
        });

        Schema::dropIfExists('project_area_delivery_logs');
    }
};
