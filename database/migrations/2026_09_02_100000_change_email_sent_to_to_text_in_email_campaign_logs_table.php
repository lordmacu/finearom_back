<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Desde que email_field_type es multi-campo, email_sent_to guarda la unión
        // de todos los correos del cliente y supera los 255 caracteres.
        DB::statement('ALTER TABLE email_campaign_logs MODIFY COLUMN email_sent_to TEXT NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE email_campaign_logs MODIFY COLUMN email_sent_to VARCHAR(255) NOT NULL');
    }
};
