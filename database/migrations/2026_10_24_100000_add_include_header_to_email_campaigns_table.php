<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // true = el correo lleva el encabezado (logo + título); las campañas existentes lo conservan
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->boolean('include_header')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn('include_header');
        });
    }
};
