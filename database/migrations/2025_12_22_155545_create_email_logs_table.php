<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // Pixel tracking ID
            
            // Email Details
            $table->string('sender_email')->nullable();
            $table->string('recipient_email');
            $table->string('subject')->nullable();
            $table->longText('content')->nullable(); // Can be large
            
            // Process Context
            $table->string('process_type')->default('system'); // campaign, purchase_order, etc.
            $table->json('metadata')->nullable(); // Extra context (order_id, client_id, etc.)
            
            // Status
            $table->string('status')->default('pending'); // pending, sent, failed
            $table->text('error_message')->nullable();
            
            // Tracking
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->integer('open_count')->default(0);
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            
            // Indexes for filtering
            $table->index('recipient_email');
            $table->index('process_type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
