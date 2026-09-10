<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL autocommitea el DDL: si el bucle de datos falla entre el
        // ADD COLUMN y el dropColumn, un re-run tiene que poder retomar
        // desde el esquema a medias sin morir con "Duplicate column name".
        $toAdd = array_values(array_filter(
            ['claims', 'color_etiqueta'],
            fn (string $column) => ! Schema::hasColumn('project_marketing_variants', $column)
        ));

        if ($toAdd !== []) {
            Schema::table('project_marketing_variants', function (Blueprint $table) use ($toAdd) {
                if (in_array('claims', $toAdd, true)) {
                    $table->text('claims')->nullable()->after('nombre');
                }
                if (in_array('color_etiqueta', $toAdd, true)) {
                    $table->string('color_etiqueta', 50)->nullable()->after('claims');
                }
            });
        }

        // Cada variante hereda el claims y el color de su PRIMERA referencia.
        // Cuando había valores distintos por referencia, se conserva el de la
        // primera y se pierden los demás: asumido en el diseño.
        foreach (DB::table('project_marketing_variants')->orderBy('id')->get() as $variant) {
            $ref = DB::table('project_marketing_variant_references')
                ->where('variant_id', $variant->id)
                ->orderBy('orden')
                ->orderBy('id')
                ->first();

            if ($ref === null) {
                continue;
            }

            DB::table('project_marketing_variants')
                ->where('id', $variant->id)
                ->update(['claims' => $ref->claims, 'color_etiqueta' => $ref->color_etiqueta]);
        }

        $toDrop = array_values(array_filter(
            ['claims', 'color_etiqueta'],
            fn (string $column) => Schema::hasColumn('project_marketing_variant_references', $column)
        ));

        if ($toDrop !== []) {
            Schema::table('project_marketing_variant_references', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn($toDrop);
            });
        }
    }

    public function down(): void
    {
        $toAdd = array_values(array_filter(
            ['color_etiqueta', 'claims'],
            fn (string $column) => ! Schema::hasColumn('project_marketing_variant_references', $column)
        ));

        if ($toAdd !== []) {
            Schema::table('project_marketing_variant_references', function (Blueprint $table) use ($toAdd) {
                if (in_array('color_etiqueta', $toAdd, true)) {
                    $table->string('color_etiqueta', 50)->nullable()->after('dosis');
                }
                if (in_array('claims', $toAdd, true)) {
                    $table->text('claims')->nullable()->after('color_etiqueta');
                }
            });
        }

        // Al revertir, todas las referencias de una variante quedan con el
        // mismo claims y el mismo color: los valores por referencia ya se perdieron.
        foreach (DB::table('project_marketing_variants')->orderBy('id')->get() as $variant) {
            DB::table('project_marketing_variant_references')
                ->where('variant_id', $variant->id)
                ->update(['claims' => $variant->claims, 'color_etiqueta' => $variant->color_etiqueta]);
        }

        $toDrop = array_values(array_filter(
            ['claims', 'color_etiqueta'],
            fn (string $column) => Schema::hasColumn('project_marketing_variants', $column)
        ));

        if ($toDrop !== []) {
            Schema::table('project_marketing_variants', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn($toDrop);
            });
        }
    }
};
