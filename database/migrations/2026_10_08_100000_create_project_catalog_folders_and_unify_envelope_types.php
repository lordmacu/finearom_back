<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carpetas virtuales (solo en base de datos) para los catálogos de Marketing y
 * unificación de los envases en la misma tabla de ítems.
 *
 * - `project_catalog_folders`: árbol por `tipo` (envase | etiqueta | piramide)
 *   con `parent_id`, sin límite de niveles.
 * - `project_catalog_items.folder_id`: carpeta del ítem (null = raíz).
 * - Los `envelope_types` pasan a `project_catalog_items` con tipo 'envase' y su
 *   pivote a `project_catalog_item_project`. Las fotos siguen en disco: solo se
 *   conserva la ruta. Las tablas viejas no se borran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_catalog_folders', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20)->index();
            $table->foreignId('parent_id')->nullable()->constrained('project_catalog_folders')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::table('project_catalog_items', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('tipo')
                ->constrained('project_catalog_folders')->nullOnDelete();
        });

        $this->migrarEnvases();
    }

    private function migrarEnvases(): void
    {
        if (!Schema::hasTable('envelope_types')) {
            return;
        }

        $mapa = [];
        foreach (DB::table('envelope_types')->orderBy('id')->get() as $envase) {
            $mapa[$envase->id] = DB::table('project_catalog_items')->insertGetId([
                'tipo'       => 'envase',
                'name'       => $envase->name,
                'category'   => $envase->category,
                'photo_path' => $envase->photo_path,
                'active'     => $envase->active,
                'created_at' => $envase->created_at,
                'updated_at' => $envase->updated_at,
            ]);
        }

        if (!Schema::hasTable('project_envelope_type')) {
            return;
        }

        foreach (DB::table('project_envelope_type')->get() as $fila) {
            if (!isset($mapa[$fila->envelope_type_id])) {
                continue;
            }
            DB::table('project_catalog_item_project')->insertOrIgnore([
                'project_id'      => $fila->project_id,
                'catalog_item_id' => $mapa[$fila->envelope_type_id],
                'created_at'      => $fila->created_at,
                'updated_at'      => $fila->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('project_catalog_items')->where('tipo', 'envase')->delete();

        Schema::table('project_catalog_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });
        Schema::dropIfExists('project_catalog_folders');
    }
};
