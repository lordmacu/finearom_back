<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_google_task_configs', function (Blueprint $table) {
            $table->id();
            $table->enum('trigger', ['on_create', 'on_observation', 'on_dispatch']);
            $table->json('user_ids')->default('[]');
            $table->timestamps();

            $table->unique('trigger');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_google_task_configs');
    }
};
