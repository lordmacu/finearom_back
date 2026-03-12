<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_categories')
            ->where('slug', 'body_care')
            ->update(['name' => 'Personal Care', 'slug' => 'personal_care']);
    }

    public function down(): void
    {
        DB::table('product_categories')
            ->where('slug', 'personal_care')
            ->update(['name' => 'Body Care', 'slug' => 'body_care']);
    }
};
