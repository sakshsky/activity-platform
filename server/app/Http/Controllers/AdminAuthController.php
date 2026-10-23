<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $data['email'] = strtolower(trim($data['email']));
        if (! Auth::attempt([...$data, 'enabled' => true])) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }
        if (! in_array($request->user()->role, ['super_admin', 'reviewer'], true)) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Use the desktop agent for employee login.']);
        }
        $request->session()->regenerate();
        Audit::record($request, 'admin.login');

        return response()->json(['user' => $request->user()]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::record($request, 'admin.logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true]);
    }

    public function weeklySummaryPreference(Request $request): JsonResponse
    {
        $data = $request->validate(['weekly_summary_enabled' => 'required|boolean']);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->enabled && $user->role === 'super_admin', 403);
            $user->weekly_summary_enabled = $data['weekly_summary_enabled'];
            $user->save();
            Audit::record($request, 'weekly_summary.preference_updated', 'user', $user->id, $data);

            return $user;
        });

        return response()->json(['user' => $user]);
    }
}
