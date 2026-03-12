<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $personalCareExists = DB::table('product_categories')
            ->where('slug', 'personal_care')
            ->exists();

        if ($personalCareExists) {
            // personal_care already exists — just remove the old body_care row if present
            DB::table('product_categories')->where('slug', 'body_care')->delete();
        } else {
            DB::table('product_categories')
                ->where('slug', 'body_care')
                ->update(['name' => 'Personal Care', 'slug' => 'personal_care']);
        }
    }

    public function down(): void
    {
        DB::table('product_categories')
            ->where('slug', 'personal_care')
            ->update(['name' => 'Body Care', 'slug' => 'body_care']);
    }
};
