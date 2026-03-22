<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_email_config', function (Blueprint $table) {
            $table->id();
            $table->enum('frequency', ['weekly', 'biweekly', 'monthly'])->default('weekly');
            // weekly: 1=lunes ... 7=domingo
            $table->unsignedTinyInteger('day_of_week')->nullable();
            // biweekly/monthly: 1-31
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('send_hour', 5)->default('08:00'); // HH:MM
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        // Fila única de configuración con valores por defecto
        DB::table('forecast_email_config')->insert([
            'frequency'    => 'weekly',
            'day_of_week'  => 1,       // lunes
            'day_of_month' => null,
            'send_hour'    => '08:00',
            'enabled'      => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_email_config');
    }
};
