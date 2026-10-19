<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_identifier')->unique();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('os', 80);
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->boolean('paused')->default(false);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_upload_at')->nullable();
            $table->unsignedInteger('notice_version')->default(1);
            $table->timestamp('notice_accepted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('screenshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->dateTime('captured_at', 6)->index();
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('storage', 20)->default('local');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->string('app_name', 160)->default('');
            $table->string('window_title', 1000)->default('');
            $table->text('url')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'event_id']);
            $table->index(['device_id', 'captured_at']);
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->dateTime('captured_at', 6)->index();
            $table->string('app_name', 160);
            $table->string('window_title', 1000)->default('');
            $table->text('url')->nullable();
            $table->timestamp('created_at');
            $table->unique(['device_id', 'event_id']);
            $table->index(['device_id', 'captured_at']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('monitoring_policies', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('monitoring_enabled')->default(true);
            $table->unsignedInteger('screenshot_interval_seconds')->default(120);
            $table->unsignedInteger('activity_interval_seconds')->default(5);
            $table->boolean('browser_urls')->default(false);
            $table->unsignedInteger('retention_days')->default(30);
            $table->unsignedInteger('audit_retention_days')->default(365);
            $table->unsignedInteger('offline_queue_max_mb')->default(512);
            $table->unsignedInteger('offline_retention_days')->default(7);
            $table->text('notice');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['monitoring_policies', 'audit_logs', 'activity_logs', 'screenshots', 'devices'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
