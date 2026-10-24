<?php

namespace App\Http\Middleware;

use App\Exceptions\SyncException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class SyncToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        if (! $request->bearerToken() || ! $token instanceof PersonalAccessToken || $token->abilities !== ['sync:write']) {
            throw new SyncException('sync_token_required', 'Se requiere un token exclusivo de sincronización.', 403);
        }

        return $next($request);
    }
}
