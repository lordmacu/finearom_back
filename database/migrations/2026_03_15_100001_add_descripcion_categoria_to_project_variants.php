<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_variants', function (Blueprint $table) {
            $table->string('categoria', 100)->nullable()->after('nombre');
            $table->text('descripcion')->nullable()->after('observaciones');
        });
    }

    public function down(): void
    {
        Schema::table('project_variants', function (Blueprint $table) {
            $table->dropColumn(['categoria', 'descripcion']);
        });
    }
};
