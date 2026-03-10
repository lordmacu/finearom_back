<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_google_task_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->enum('trigger', ['on_create', 'on_status_change', 'near_deadline']);
            $table->json('user_ids');
            $table->timestamps();

            $table->unique(['project_id', 'trigger']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_google_task_configs');
    }
};
