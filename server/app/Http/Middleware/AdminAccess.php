<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAccess
{
    public function handle(Request $request, Closure $next, string $role = 'reviewer'): Response
    {
        $user = $request->user();
        abort_unless($user && $user->enabled, 403, 'This account is disabled.');
        abort_unless(in_array($user->role, ['super_admin', 'reviewer'], true), 403, 'Administrator access required.');
        if ($role === 'super_admin') {
            abort_unless($user->role === 'super_admin', 403, 'Super admin access required.');
        }

        return $next($request);
    }
}
