<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "¿Requiere aceites?" en la sección Desarrollo y aplicaciones. Arranca en no para todos. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('requiere_aceites')->default(false)->after('requiere_aplicaciones');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('requiere_aceites');
        });
    }
};
