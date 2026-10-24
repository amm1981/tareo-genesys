<?php

namespace App\Http\Middleware;

use App\Exceptions\SyncException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class QueryToken
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $request->bearerToken() || ! $token instanceof PersonalAccessToken || $token->abilities !== ['costs:read'] || ! $user->api_enabled || ! $user->api_username) {
            throw new SyncException('query_token_required', 'Se requiere una cuenta habilitada y un token exclusivo de consulta.', 403);
        }

        return $next($request);
    }
}
