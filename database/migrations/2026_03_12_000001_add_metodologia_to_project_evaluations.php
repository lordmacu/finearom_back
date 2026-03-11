<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->string('metodologia')->nullable()->after('tipos'); // olfativa, instrumental, mixta
        });

        Schema::table('project_variants', function (Blueprint $table) {
            $table->foreignId('benchmark_reference_id')
                ->nullable()
                ->after('observaciones')
                ->constrained('finearom_references')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->dropColumn('metodologia');
        });

        Schema::table('project_variants', function (Blueprint $table) {
            $table->dropForeign(['benchmark_reference_id']);
            $table->dropColumn('benchmark_reference_id');
        });
    }
};
