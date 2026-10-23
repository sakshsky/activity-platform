<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EmployeeRecord
{
    public static function personalRules(): array
    {
        return [
            'legal_name' => 'nullable|string|max:120', 'preferred_name' => 'nullable|string|max:120',
            'date_of_birth' => 'nullable|date_format:Y-m-d|before:today', 'gender' => 'nullable|string|max:40',
            'nationality' => 'nullable|string|max:100', 'personal_email' => 'nullable|email|max:255',
            'alternate_phone' => 'nullable|string|max:40',
            'current_address' => 'nullable|string|max:1000', 'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120', 'postal_code' => 'nullable|string|max:30', 'country' => 'nullable|string|max:100',
            'permanent_address' => 'nullable|string|max:1500',
            'emergency_name' => 'nullable|string|max:120', 'emergency_relationship' => 'nullable|string|max:80',
            'emergency_phone' => 'nullable|string|max:40', 'emergency_alternate_phone' => 'nullable|string|max:40',
            'qualification' => 'nullable|string|max:150', 'institution' => 'nullable|string|max:200',
            'graduation_year' => 'nullable|integer|min:1900|max:'.(now()->year + 10),
            'skills' => 'nullable|string|max:2000',
        ];
    }

    public static function employmentRules(): array
    {
        return ['employee_code' => 'nullable|string|max:60', 'department' => 'nullable|string|max:120',
            'job_title' => 'nullable|string|max:120', 'manager' => 'nullable|string|max:120',
            'employment_type' => 'nullable|in:full_time,part_time,contract,intern',
            'employment_status' => 'nullable|in:active,probation,on_leave,exited',
            'joining_date' => 'nullable|date_format:Y-m-d', 'probation_end_date' => 'nullable|date_format:Y-m-d',
            'office' => 'nullable|string|max:150', 'work_mode' => 'nullable|in:office,remote,hybrid'];
    }

    public static function present(User $user, string $fallbackTimezone = 'UTC'): array
    {
        $row = DB::table('employee_profiles')->where('user_id', $user->id)->first();
        $personal = $row?->personal_details ? json_decode(Crypt::decryptString($row->personal_details), true, 512, JSON_THROW_ON_ERROR) : [];
        $employment = $row?->employment_details ? json_decode($row->employment_details, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['name' => $user->name, 'email' => $user->email, 'phone' => $row->phone ?? '', 'location' => $row->location ?? '',
            'timezone' => $row->timezone ?? $fallbackTimezone, 'revision' => $row->profile_revision ?? 0,
            'personal' => array_replace(array_fill_keys(array_keys(self::personalRules()), ''), $personal),
            'employment' => array_replace(array_fill_keys(array_keys(self::employmentRules()), ''), $employment),
            'reviewed_at' => $row?->reviewed_at ? CarbonImmutable::parse($row->reviewed_at, 'UTC')->toIso8601String() : null,
            'updated_at' => $row?->updated_at ? CarbonImmutable::parse($row->updated_at, 'UTC')->toIso8601String() : null];
    }
}
