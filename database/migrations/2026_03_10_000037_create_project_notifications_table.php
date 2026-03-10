<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('tipo', 50);
            $table->string('titulo', 200);
            $table->text('mensaje');
            $table->json('data')->nullable();
            $table->timestamp('leida_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'leida_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_notifications');
    }
};
