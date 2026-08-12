<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_trackings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('tracking_number', 100);
            $table->string('carrier', 30);
            $table->enum('status', [
                'pendiente', 'en_transito', 'entregado', 'devuelto', 'novedad', 'sin_datos',
            ])->default('pendiente');
            $table->dateTime('last_event_at')->nullable();
            $table->string('last_event_code', 20)->nullable();
            $table->string('last_event_description', 255)->nullable();
            $table->string('last_event_location', 120)->nullable();
            $table->date('dispatch_date')->nullable();
            $table->decimal('total_kg', 12, 2)->default(0);
            $table->unsignedInteger('partials_count')->default(0);
            $table->dateTime('checked_at')->nullable();
            $table->unsignedInteger('check_attempts')->default(0);
            $table->boolean('is_final')->default(false);
            $table->string('error_message', 255)->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'tracking_number'], 'uq_shipment_order_tracking');
            $table->index(['status', 'carrier'], 'idx_shipment_status_carrier');
            $table->index('checked_at', 'idx_shipment_checked_at');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
        });

        Schema::create('shipment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_tracking_id');
            $table->dateTime('occurred_at');
            $table->string('code', 20)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('location', 120)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['shipment_tracking_id', 'occurred_at', 'code'], 'uq_event_tracking_time_code');
            $table->index(['shipment_tracking_id', 'occurred_at'], 'idx_event_tracking_time');
            $table->foreign('shipment_tracking_id')->references('id')->on('shipment_trackings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_events');
        Schema::dropIfExists('shipment_trackings');
    }
};
