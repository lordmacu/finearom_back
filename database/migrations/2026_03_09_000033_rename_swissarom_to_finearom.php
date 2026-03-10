<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename tables
        Schema::rename('swissarom_references', 'finearom_references');
        Schema::rename('swissarom_price_history', 'finearom_price_history');

        // Rename FK column in project_proposals
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->renameColumn('swissarom_reference_id', 'finearom_reference_id');
        });

        // Rename FK column in finearom_price_history
        Schema::table('finearom_price_history', function (Blueprint $table) {
            $table->renameColumn('swissarom_reference_id', 'finearom_reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('finearom_price_history', function (Blueprint $table) {
            $table->renameColumn('finearom_reference_id', 'swissarom_reference_id');
        });

        Schema::table('project_proposals', function (Blueprint $table) {
            $table->renameColumn('finearom_reference_id', 'swissarom_reference_id');
        });

        Schema::rename('finearom_price_history', 'swissarom_price_history');
        Schema::rename('finearom_references', 'swissarom_references');
    }
};
