<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Seed the existing hardcoded categories
        DB::table('product_categories')->insert([
            ['name' => 'Body Care',      'slug' => 'body_care',      'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Home Care',      'slug' => 'home_care',      'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Air Care',       'slug' => 'air_care',       'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Fine Fragrance', 'slug' => 'fine_fragrance', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
