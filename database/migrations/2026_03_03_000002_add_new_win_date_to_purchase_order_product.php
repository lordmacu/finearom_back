<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_product', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_product', 'new_win_date')) {
                $table->date('new_win_date')->nullable()->after('new_win');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_product', function (Blueprint $table) {
            $table->dropColumn('new_win_date');
        });
    }
};
