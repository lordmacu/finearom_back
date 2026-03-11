<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE clients c
            JOIN executives e
                ON LOWER(TRIM(CAST(c.executive AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(CAST(e.name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))
            SET c.executive = e.email
            WHERE c.executive NOT LIKE '%@%' COLLATE utf8mb4_unicode_ci
              AND c.executive IS NOT NULL
              AND c.executive != ''
        ");

        DB::statement("
            UPDATE clients
            SET executive = executive_email
            WHERE executive_email IS NOT NULL
              AND executive_email != ''
              AND (executive IS NULL OR executive = '' OR executive NOT LIKE '%@%' COLLATE utf8mb4_unicode_ci)
        ");
    }

    public function down(): void
    {
    }
};
