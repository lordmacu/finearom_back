<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'first_dispatch_date')) {
                $table->date('first_dispatch_date')->nullable()->after('lead_time');
            }
            if (! Schema::hasColumn('clients', 'estimated_launch_date')) {
                $table->date('estimated_launch_date')->nullable()->after('first_dispatch_date');
            }
            if (! Schema::hasColumn('clients', 'first_dispatch_quantity')) {
                $table->decimal('first_dispatch_quantity', 10, 2)->nullable()->after('estimated_launch_date');
            }
            if (! Schema::hasColumn('clients', 'purchase_frequency')) {
                $table->string('purchase_frequency')->nullable()->after('first_dispatch_quantity');
            }
            if (! Schema::hasColumn('clients', 'estimated_monthly_quantity')) {
                $table->decimal('estimated_monthly_quantity', 10, 2)->nullable()->after('purchase_frequency');
            }
            if (! Schema::hasColumn('clients', 'product_category')) {
                $table->string('product_category')->nullable()->after('estimated_monthly_quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'first_dispatch_date',
                'estimated_launch_date',
                'first_dispatch_quantity',
                'purchase_frequency',
                'estimated_monthly_quantity',
                'product_category',
            ]);
        });
    }
};
