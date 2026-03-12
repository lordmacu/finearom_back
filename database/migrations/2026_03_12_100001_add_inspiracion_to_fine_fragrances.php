<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fine_fragrances', function (Blueprint $table) {
            $table->string('inspiracion')->nullable()->after('contratipo');
        });
    }

    public function down(): void
    {
        Schema::table('fine_fragrances', function (Blueprint $table) {
            $table->dropColumn('inspiracion');
        });
    }
};
