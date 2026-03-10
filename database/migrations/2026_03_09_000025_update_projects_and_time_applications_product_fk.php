<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // projects: drop FK to products, re-apuntar a project_product_types
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')
                  ->references('id')->on('project_product_types')
                  ->nullOnDelete();
        });

        // time_applications: drop FK a products, re-apuntar a project_product_types
        Schema::table('time_applications', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')
                  ->references('id')->on('project_product_types')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_applications', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }
};
