<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correos internos por área (compartidos por todos los usuarios de campañas)
        Schema::create('internal_emails', function (Blueprint $table) {
            $table->id();
            $table->string('area', 30);
            $table->string('email');
            $table->timestamps();

            $table->unique(['area', 'email']);
        });

        // Correos internos elegidos en una campaña (se guardan resueltos, como foto del momento)
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->json('internal_emails')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn('internal_emails');
        });
        Schema::dropIfExists('internal_emails');
    }
};
