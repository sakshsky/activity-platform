<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_policies', function (Blueprint $table) {
            $table->unsignedInteger('idle_threshold_seconds')->default(120)->change();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_policies', function (Blueprint $table) {
            $table->unsignedInteger('idle_threshold_seconds')->default(300)->change();
        });
    }
};
