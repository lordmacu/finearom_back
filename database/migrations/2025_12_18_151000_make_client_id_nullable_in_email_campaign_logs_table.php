<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_campaign_logs')) {
            return;
        }

        if (! Schema::hasColumn('email_campaign_logs', 'client_id')) {
            return;
        }

        // Avoid requiring doctrine/dbal just to "change()" a column.
        // This module also supports logs for custom emails (no real client).
        DB::statement('ALTER TABLE `email_campaign_logs` MODIFY `client_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_campaign_logs')) {
            return;
        }

        if (! Schema::hasColumn('email_campaign_logs', 'client_id')) {
            return;
        }

        // Going back to NOT NULL may fail if there are already rows with NULL.
        // We keep it best-effort.
        DB::statement('ALTER TABLE `email_campaign_logs` MODIFY `client_id` BIGINT UNSIGNED NOT NULL');
    }
};

