<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedBigInteger('idle_seconds')->nullable();
        });
        Schema::table('monitoring_policies', function (Blueprint $table) {
            $table->unsignedInteger('idle_threshold_seconds')->default(300);
            $table->unsignedInteger('idle_alert_minutes')->default(10);
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedTinyInteger('app_switches')->nullable();
        });
        Schema::create('role_domain_rules', function (Blueprint $table) {
            $table->id();
            $table->string('cohort_key', 64);
            $table->string('domain', 253);
            $table->string('category', 30);
            $table->string('reason', 500);
            $table->timestamps();
            $table->unique(['cohort_key', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_domain_rules');
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('idle_seconds'));
        Schema::table('activity_logs', fn (Blueprint $t) => $t->dropColumn(['duration_seconds', 'app_switches']));
        Schema::table('monitoring_policies', fn (Blueprint $t) => $t->dropColumn(['idle_threshold_seconds', 'idle_alert_minutes']));
    }
};
