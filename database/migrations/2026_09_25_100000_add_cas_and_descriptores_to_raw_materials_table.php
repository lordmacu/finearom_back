<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->string('cas', 255)->nullable()->after('nombre');
            $table->text('descriptores')->nullable()->after('cas');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn(['cas', 'descriptores']);
        });
    }
};
