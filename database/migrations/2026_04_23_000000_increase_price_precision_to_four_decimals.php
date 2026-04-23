<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', ''), 'STRICT_TRANS_TABLES', '')");

        DB::statement('ALTER TABLE products MODIFY price DECIMAL(15, 4) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE purchase_order_product MODIFY price DECIMAL(15, 4) NOT NULL');
        DB::statement('ALTER TABLE product_price_history MODIFY price DECIMAL(15, 4) NOT NULL');
    }

    public function down(): void
    {
        DB::statement("SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', ''), 'STRICT_TRANS_TABLES', '')");

        DB::statement('ALTER TABLE products MODIFY price DECIMAL(15, 2) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE purchase_order_product MODIFY price DECIMAL(10, 2) NOT NULL');
        DB::statement('ALTER TABLE product_price_history MODIFY price DECIMAL(10, 2) NOT NULL');
    }
};
