<?php

namespace App\Http\Middleware;

use App\Exceptions\SyncException;
use Closure;
use Illuminate\Http\Request;

class ApiBoundary
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isSecure() && (! app()->environment('local', 'testing') || ! in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true))) {
            throw new SyncException('https_required', 'Se requiere HTTPS.', 403);
        }
        if ((int) $request->header('Content-Length', 0) > config('sync.max_body_bytes') || strlen($request->getContent()) > config('sync.max_body_bytes')) {
            throw new SyncException('body_too_large', 'Solicitud demasiado grande.', 413);
        }
        if ($request->isMethod('POST') && ! $request->isJson()) {
            throw new SyncException('json_required', 'Se requiere application/json.', 415);
        }

        return $next($request);
    }
}
