<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert bare string values (e.g. "compras_email") to JSON arrays (e.g. ["compras_email"])
        DB::statement("UPDATE email_campaigns SET email_field_type = JSON_ARRAY(email_field_type) WHERE NOT JSON_VALID(email_field_type)");

        DB::statement("ALTER TABLE email_campaigns MODIFY COLUMN email_field_type TEXT NOT NULL");
    }

    public function down(): void
    {
        // Restore: extract first element of the JSON array back to a plain string
        DB::statement("UPDATE email_campaigns SET email_field_type = JSON_UNQUOTE(JSON_EXTRACT(email_field_type, '$[0]')) WHERE JSON_VALID(email_field_type)");

        DB::statement("ALTER TABLE email_campaigns MODIFY COLUMN email_field_type VARCHAR(255) NOT NULL");
    }
};
