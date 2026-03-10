<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->decimal('precio_snapshot', 10, 2)->nullable()->after('swissarom_reference_id')
                ->comment('Precio de la referencia Swissarom al momento de crear la propuesta');
        });
    }

    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropColumn('precio_snapshot');
        });
    }
};
