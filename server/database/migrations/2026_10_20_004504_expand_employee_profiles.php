<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->longText('personal_details')->nullable();
            $table->unsignedInteger('profile_revision')->default(0);
            $table->json('employment_details')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['personal_details', 'employment_details', 'reviewed_at', 'reviewed_by', 'profile_revision']);
        });
    }
};
