<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless($user && $user->enabled && $user->role === 'employee' && $token && isset($token->id) && $token->can('device:collect'), 403, 'Device access revoked.');
        $device = Device::where('token_id', $token->id)->where('user_id', $user->id)->first();
        abort_unless($device && ! $device->disabled_at, 403, 'Device access revoked.');
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
