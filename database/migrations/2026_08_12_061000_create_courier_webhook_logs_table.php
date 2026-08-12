<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('carrier', 30);
            $table->string('endpoint', 40);
            $table->enum('environment', ['test', 'prod']);
            $table->string('ip', 45);
            $table->string('tracking_number', 100)->nullable();
            $table->json('payload');
            $table->boolean('accepted')->default(true);
            $table->string('rejection_reason', 255)->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index('tracking_number', 'idx_webhook_tracking_number');
            $table->index(['carrier', 'endpoint'], 'idx_webhook_carrier_endpoint');
            $table->index('created_at', 'idx_webhook_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_webhook_logs');
    }
};
