<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las variantes que crea Desarrollo (project_variants) se crean solas en
 * Marketing: este campo enlaza la variante de Marketing con su origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_marketing_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('project_variant_id')->nullable()->after('project_id');
            $table->foreign('project_variant_id')->references('id')->on('project_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing_variants', function (Blueprint $table) {
            $table->dropForeign(['project_variant_id']);
            $table->dropColumn('project_variant_id');
        });
    }
};
