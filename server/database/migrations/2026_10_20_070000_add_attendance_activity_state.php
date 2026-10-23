<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_sessions', function (Blueprint $table) {
            $table->string('activity_state', 20)->default('legacy');
        });
        Schema::table('work_intervals', function (Blueprint $table) {
            $table->string('activity_state', 20)->default('legacy');
        });
    }

    public function down(): void
    {
        Schema::table('work_intervals', fn (Blueprint $table) => $table->dropColumn('activity_state'));
        Schema::table('employee_sessions', fn (Blueprint $table) => $table->dropColumn('activity_state'));
    }
};
