<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_statistics', function (Blueprint $table) {
            // Payload JSON to store extended KPI snapshots (lead time, fill rate, alerts, etc.)
            $table->json('extended_stats')->nullable()->after('unique_clients_with_planned_dispatches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_statistics', function (Blueprint $table) {
            $table->dropColumn('extended_stats');
        });
    }
};
