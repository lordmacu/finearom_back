<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La selección de envase pasa de ser única (envelope_type_id en projects)
     * a múltiple (tabla pivote). Se migran los datos existentes antes de
     * borrar la columna vieja.
     */
    public function up(): void
    {
        Schema::create('project_envelope_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('envelope_type_id')->constrained('envelope_types')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'envelope_type_id']);
        });

        $existing = DB::table('projects')
            ->whereNotNull('envelope_type_id')
            ->pluck('envelope_type_id', 'id');

        $now = now();
        $rows = $existing->map(fn($envelopeTypeId, $projectId) => [
            'project_id'       => $projectId,
            'envelope_type_id' => $envelopeTypeId,
            'created_at'       => $now,
            'updated_at'       => $now,
        ])->values()->all();

        if (!empty($rows)) {
            DB::table('project_envelope_type')->insert($rows);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['envelope_type_id']);
            $table->dropColumn('envelope_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('envelope_type_id')
                ->nullable()
                ->after('tipo_etiquetado')
                ->constrained('envelope_types')
                ->nullOnDelete();
        });

        $first = DB::table('project_envelope_type')
            ->orderBy('id')
            ->get()
            ->unique('project_id');

        foreach ($first as $row) {
            DB::table('projects')->where('id', $row->project_id)
                ->update(['envelope_type_id' => $row->envelope_type_id]);
        }

        Schema::dropIfExists('project_envelope_type');
    }
};
