<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_policies', function (Blueprint $t) {
            $t->string('deletion_policy', 20)->default('neutral');
        });
        Schema::table('screenshots', function (Blueprint $t) {
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('deleted_reason')->nullable();
            $t->string('placeholder_path')->nullable();
            $t->boolean('deletion_cleanup_pending')->default(false)->index();
        });
        Schema::create('deletion_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('employee_name');
            $t->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $t->string('capture_type', 20);
            $t->uuid('capture_id');
            $t->timestamp('deleted_at')->index();
            $t->text('reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->boolean('flagged')->default(false);
            $t->text('flag_reason')->nullable();
            $t->index(['user_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_events');
        Schema::table('screenshots', function (Blueprint $t) {
            $t->dropForeign(['deleted_by']);
            $t->dropColumn(['deleted_at', 'deleted_by', 'deleted_reason', 'placeholder_path', 'deletion_cleanup_pending']);
        });
        Schema::table('monitoring_policies', fn (Blueprint $t) => $t->dropColumn('deletion_policy'));
    }
};
