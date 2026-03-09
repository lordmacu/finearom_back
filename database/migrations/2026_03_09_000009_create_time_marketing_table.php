<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_marketing', function (Blueprint $table) {
            $table->id();
            $table->integer('grupo');
            $table->string('solicitud');
            $table->integer('valor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_marketing');
    }
};
