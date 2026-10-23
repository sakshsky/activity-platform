<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_policies', function (Blueprint $t) {
            $t->boolean('video_enabled')->default(false);
            $t->boolean('anomaly_enabled')->default(false);
            $t->unsignedInteger('video_interval_seconds')->default(300);
            $t->unsignedInteger('video_clip_seconds')->default(10);
            $t->unsignedInteger('video_retention_days')->default(7);
            $t->unsignedInteger('video_quota_mb')->default(1024);
        });
        Schema::table('devices', function (Blueprint $t) {
            $t->unsignedInteger('modules_notice_version')->nullable();
            $t->timestamp('modules_accepted_at')->nullable();
        });
        Schema::create('video_captures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $t->uuid('event_id');
            $t->dateTime('captured_at');
            $t->string('path');
            $t->string('storage', 20);
            $t->unsignedBigInteger('bytes');
            $t->string('sha256', 64);
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->decimal('duration_seconds', 6, 2);
            $t->unsignedInteger('notice_version');
            $t->timestamps();
            $t->unique(['device_id', 'event_id']);
            $t->index(['device_id', 'captured_at']);
        });
        Schema::create('activity_anomalies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $t->uuid('event_id');
            $t->dateTime('started_at');
            $t->dateTime('ended_at');
            $t->string('rule', 80);
            $t->json('evidence');
            $t->string('status', 30)->default('pending');
            $t->text('employee_note')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
            $t->unique(['device_id', 'event_id']);
            $t->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_anomalies');
        Schema::dropIfExists('video_captures');
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn(['modules_notice_version', 'modules_accepted_at']));
        Schema::table('monitoring_policies', fn (Blueprint $t) => $t->dropColumn(['video_enabled', 'anomaly_enabled', 'video_interval_seconds', 'video_clip_seconds', 'video_retention_days', 'video_quota_mb']));
    }
};
