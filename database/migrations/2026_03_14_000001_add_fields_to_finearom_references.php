<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finearom_references', function (Blueprint $table) {
            $table->decimal('dosis', 6, 2)->nullable()->after('precio')->comment('Dosis en g/kg');
            $table->string('tipo_producto')->nullable()->after('dosis');
            $table->text('descripcion_olfativa')->nullable()->after('tipo_producto');
        });
    }

    public function down(): void
    {
        Schema::table('finearom_references', function (Blueprint $table) {
            $table->dropColumn(['dosis', 'tipo_producto', 'descripcion_olfativa']);
        });
    }
};
