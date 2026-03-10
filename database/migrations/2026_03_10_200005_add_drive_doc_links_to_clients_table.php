<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // JSON: { "rut_file": "https://...", "camara_comercio_file": "https://...", ... }
            $table->json('drive_doc_links')->nullable()->after('estados_financieros_file');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('drive_doc_links');
        });
    }
};
