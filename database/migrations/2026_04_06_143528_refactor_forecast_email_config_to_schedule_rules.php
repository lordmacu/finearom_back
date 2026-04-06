<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_email_config', function (Blueprint $table) {
            $table->json('schedule_rules')->nullable()->after('enabled');
            $table->dropColumn(['frequency', 'day_of_week', 'day_of_month']);
        });
    }

    public function down(): void
    {
        Schema::table('forecast_email_config', function (Blueprint $table) {
            $table->dropColumn('schedule_rules');
            $table->enum('frequency', ['weekly', 'biweekly', 'monthly'])->default('weekly')->after('enabled');
            $table->tinyInteger('day_of_week')->nullable()->after('frequency');
            $table->tinyInteger('day_of_month')->nullable()->after('day_of_week');
        });
    }
};
