<?php

use App\Exceptions\WalletException;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([AuthenticateApiToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => true);
        $exceptions->render(function (WalletException $exception): JsonResponse {
            return response()->json(['error' => $exception->error, 'message' => $exception->getMessage()], $exception->status);
        });
        $exceptions->render(function (HttpExceptionInterface $exception): JsonResponse {
            return response()->json([
                'error' => $exception->getStatusCode() === 404 ? 'not_found' : 'invalid_request',
                'message' => $exception->getStatusCode() === 404 ? 'Endpoint not found.' : 'Request not supported.',
            ], $exception->getStatusCode());
        });
        $exceptions->render(function (Throwable $exception): JsonResponse {
            if ((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception)) {
                return response()->json(['error' => 'temporarily_unavailable', 'message' => 'Database contention exceeded the retry budget.'], 503);
            }

            return response()->json(['error' => 'internal_error', 'message' => 'An unexpected server error occurred.'], 500);
        });
    })->create();
