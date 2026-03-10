<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_files', function (Blueprint $table) {
            $table->string('drive_file_id')->nullable()->after('size');
            $table->string('drive_link')->nullable()->after('drive_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_files', function (Blueprint $table) {
            $table->dropColumn(['drive_file_id', 'drive_link']);
        });
    }
};
