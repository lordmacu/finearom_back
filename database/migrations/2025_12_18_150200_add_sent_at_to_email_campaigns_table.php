<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_campaigns')) {
            return;
        }

        if (Schema::hasColumn('email_campaigns', 'sent_at')) {
            return;
        }

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('email_campaigns')) {
            return;
        }

        if (!Schema::hasColumn('email_campaigns', 'sent_at')) {
            return;
        }

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });
    }
};

