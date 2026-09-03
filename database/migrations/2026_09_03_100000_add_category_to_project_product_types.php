<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_product_types', function (Blueprint $table) {
            // `categoria` (integer) guarda una taxonomía del legacy que no
            // corresponde con product_categories; se deja intacta.
            $table->foreignId('product_category_id')
                ->nullable()
                ->after('categoria')
                ->constrained('product_categories')
                ->nullOnDelete();

            $table->string('grupo', 60)->nullable()->after('product_category_id');
            $table->boolean('active')->default(true)->after('grupo');

            $table->index(['product_category_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('project_product_types', function (Blueprint $table) {
            $table->dropIndex(['product_category_id', 'active']);
            $table->dropForeign(['product_category_id']);
            $table->dropColumn(['product_category_id', 'grupo', 'active']);
        });
    }
};
