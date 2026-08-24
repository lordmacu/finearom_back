<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La columna ya existe en las bases de datos actuales (se creó fuera de
     * migraciones), por eso el guard: esta migración solo la agrega donde falte.
     */
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'requires_study')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('requires_study')->default(false)->after('estados_financieros_file');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'requires_study')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('requires_study');
        });
    }
};
