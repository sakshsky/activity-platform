<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
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
}
