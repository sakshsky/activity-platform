<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('token_id')->unique();
            $table->string('device_name', 120);
            $table->string('kind', 30)->default('login');
            $table->dateTime('started_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('end_reason', 40)->nullable();
            $table->boolean('eligible')->default(false);
            $table->index(['user_id', 'started_at']);
        });
        Schema::create('work_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->index(['user_id', 'ended_at']);
        });
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('phone', 40)->default('');
            $table->string('location', 120)->default('');
            $table->string('timezone', 80)->default('UTC');
            $table->timestamps();
        });
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type', 30);
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['leave_requests', 'employee_profiles', 'work_intervals', 'employee_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
