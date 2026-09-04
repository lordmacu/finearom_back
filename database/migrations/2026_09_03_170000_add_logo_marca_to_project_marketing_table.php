<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->json('logo_marca')->nullable()->after('marca');
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->dropColumn('logo_marca');
        });
    }
};
