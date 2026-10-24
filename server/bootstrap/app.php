<?php

use App\Exceptions\SyncException;
use App\Http\Middleware\ApiBoundary;
use App\Http\Middleware\SyncToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->remove([TrimStrings::class, ConvertEmptyStringsToNull::class]);
        $middleware->api(prepend: [ApiBoundary::class]);
        $middleware->alias(['sync.token' => SyncToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        // Registrar solo clase y UUID técnico: QueryException puede incluir filas completas.
        $exceptions->report(function (Throwable $e) {
            if (! $e instanceof SyncException && ! $e instanceof ValidationException && ! $e instanceof AuthenticationException && ! $e instanceof HttpExceptionInterface) {
                Log::error('Fallo interno', ['exception_type' => get_class($e)]);
            }
        })->stop();
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof SyncException) {
                return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
            }
            if ($e instanceof ValidationException) {
                return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'Entrada inválida.']], 422);
            }
            $status = $e instanceof AuthenticationException ? 401 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500);

            return response()->json(['error' => ['code' => 'http_'.$status, 'message' => $status >= 500 ? 'Error interno. Consulte al administrador.' : 'Solicitud rechazada.']], $status);
        });
    })->create();
