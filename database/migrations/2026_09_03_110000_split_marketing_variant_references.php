<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una variante pasa a agrupar hasta 3 referencias, cada una con su código,
     * aplicación, dosis, color y claims. Las filas que ya existían se
     * convierten en una variante con una sola referencia.
     */
    public function up(): void
    {
        Schema::create('project_marketing_variant_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')
                ->constrained('project_marketing_variants')
                ->cascadeOnDelete();
            $table->string('referencia', 200)->nullable();
            $table->string('codigo', 100)->nullable();
            $table->string('aplicacion', 200)->nullable();
            $table->decimal('dosis', 8, 2)->nullable();
            $table->string('color_etiqueta', 50)->nullable();
            $table->text('claims')->nullable();
            $table->integer('orden')->default(0);
            $table->timestamps();
        });

        Schema::table('project_marketing_variants', function (Blueprint $table) {
            $table->string('nombre', 200)->nullable()->after('project_id');
            $table->integer('orden')->default(0)->after('nombre');
        });

        // Cada variante existente se vuelve su propia primera referencia.
        DB::table('project_marketing_variants')->orderBy('id')->chunkById(100, function ($variantes) {
            foreach ($variantes as $variante) {
                DB::table('project_marketing_variant_references')->insert([
                    'variant_id'     => $variante->id,
                    'referencia'     => $variante->referencia,
                    'codigo'         => $variante->codigo,
                    'aplicacion'     => $variante->aplicacion,
                    'dosis'          => $variante->dosis,
                    'color_etiqueta' => $variante->color_etiqueta,
                    'claims'         => $variante->claims,
                    'orden'          => 0,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                DB::table('project_marketing_variants')
                    ->where('id', $variante->id)
                    ->update(['nombre' => $variante->referencia]);
            }
        });

        Schema::table('project_marketing_variants', function (Blueprint $table) {
            $table->dropColumn([
                'referencia', 'codigo', 'aplicacion', 'dosis', 'color_etiqueta', 'claims',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing_variants', function (Blueprint $table) {
            $table->string('referencia', 200)->nullable();
            $table->string('codigo', 100)->nullable();
            $table->string('aplicacion', 200)->nullable();
            $table->decimal('dosis', 8, 2)->nullable();
            $table->string('color_etiqueta', 50)->nullable();
            $table->text('claims')->nullable();
        });

        // Se recupera la primera referencia de cada variante; las otras dos se
        // pierden porque el esquema viejo no las admite.
        DB::table('project_marketing_variant_references')
            ->where('orden', 0)
            ->orderBy('id')
            ->chunkById(100, function ($refs) {
                foreach ($refs as $ref) {
                    DB::table('project_marketing_variants')
                        ->where('id', $ref->variant_id)
                        ->update([
                            'referencia'     => $ref->referencia,
                            'codigo'         => $ref->codigo,
                            'aplicacion'     => $ref->aplicacion,
                            'dosis'          => $ref->dosis,
                            'color_etiqueta' => $ref->color_etiqueta,
                            'claims'         => $ref->claims,
                        ]);
                }
            });

        Schema::dropIfExists('project_marketing_variant_references');

        Schema::table('project_marketing_variants', function (Blueprint $table) {
            $table->dropColumn(['nombre', 'orden']);
        });
    }
};
