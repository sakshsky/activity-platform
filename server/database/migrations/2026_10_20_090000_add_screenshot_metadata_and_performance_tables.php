<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('capture_state', 20)->nullable();
            $table->unsignedInteger('idle_seconds')->nullable();
            $table->unsignedInteger('idle_threshold_seconds')->nullable();
            $table->string('agent_version', 40)->nullable();
        });
        Schema::create('performance_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('title', 180);
            $table->text('criteria');
            $table->date('due_date');
            $table->unsignedTinyInteger('points')->default(1);
            $table->string('status', 30)->default('assigned');
            $table->text('evidence')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->unsignedTinyInteger('quality')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->references('id')->on('users')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'due_date']);
        });
        Schema::create('work_explanations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('kind', 30);
            $table->unsignedSmallInteger('minutes');
            $table->text('note');
            $table->timestamps();
            $table->index(['user_id', 'work_date']);
        });
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->date('week');
            $table->string('cohort_key', 64);
            $table->string('cohort_label', 400);
            $table->decimal('score', 5, 2);
            $table->unsignedTinyInteger('collaboration');
            $table->text('manager_note');
            $table->longText('snapshot');
            $table->foreignUuid('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $table->dateTime('published_at');
            $table->unique(['user_id', 'week']);
            $table->index(['week', 'cohort_key']);
        });
        Schema::create('role_app_rules', function (Blueprint $table) {
            $table->id();
            $table->string('cohort_key', 64);
            $table->string('app_name', 160);
            $table->string('category', 30);
            $table->string('reason', 500);
            $table->timestamps();
            $table->unique(['cohort_key', 'app_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_app_rules');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('work_explanations');
        Schema::dropIfExists('performance_tasks');
        Schema::table('screenshots', fn (Blueprint $table) => $table->dropColumn(['width', 'height', 'capture_state', 'idle_seconds', 'idle_threshold_seconds', 'agent_version']));
    }
};
