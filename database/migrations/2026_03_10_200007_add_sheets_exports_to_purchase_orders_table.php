<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // JSON con los eventos ya exportados a Sheets.
            // Ejemplo: ["parcial_2026-03-10T14:30:00", "completed_2026-03-11T09:00:00"]
            $table->json('sheets_exports')->nullable()->after('drive_attachment_links');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('sheets_exports');
        });
    }
};
