<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normaliza clients.executive para que siempre contenga el email de la ejecutiva.
     * 1) Hace JOIN con executives comparando nombre (case-insensitive) → reemplaza con email
     * 2) Donde executive_email tiene valor y executive no es email → sincroniza
     */
    public function up(): void
    {
        // Paso 1: matching por nombre contra tabla executives
        DB::statement("
            UPDATE clients c
            JOIN executives e
                ON LOWER(TRIM(c.executive)) = LOWER(TRIM(e.name))
            SET c.executive = e.email
            WHERE c.executive NOT LIKE '%@%'
              AND c.executive IS NOT NULL
              AND c.executive != ''
        ");

        // Paso 2: usar executive_email donde executive aún no es email
        DB::statement("
            UPDATE clients
            SET executive = executive_email
            WHERE executive_email IS NOT NULL
              AND executive_email != ''
              AND (executive IS NULL OR executive = '' OR executive NOT LIKE '%@%')
        ");
    }

    public function down(): void
    {
        // No reversible
    }
};
