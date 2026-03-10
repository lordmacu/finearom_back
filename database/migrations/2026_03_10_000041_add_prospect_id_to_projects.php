<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Make client_id nullable since prospect projects won't have a client_id
            $table->unsignedBigInteger('client_id')->nullable()->change();
            // Add prospect_id FK
            $table->foreignId('prospect_id')
                ->nullable()
                ->after('client_id')
                ->constrained('prospects')
                ->nullOnDelete();
            // Track original legacy project ID for idempotency
            $table->unsignedBigInteger('legacy_id')
                ->nullable()
                ->unique()
                ->after('prospect_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['prospect_id']);
            $table->dropColumn(['prospect_id', 'legacy_id']);
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }
};
