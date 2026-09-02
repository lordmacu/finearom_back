<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->text('bench_text')->nullable()->after('benchmark_reference_id');
            $table->string('bench_image')->nullable()->after('bench_text');
        });
    }

    public function down(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->dropColumn(['bench_image', 'bench_text']);
        });
    }
};