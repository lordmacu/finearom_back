<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_responses', function (Blueprint $table) {
            $table->id();
            $table->integer('num_variantes_min');
            $table->integer('num_variantes_max');
            $table->integer('grupo');
            $table->integer('valor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_responses');
    }
};
