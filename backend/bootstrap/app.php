<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\OriginGuard;
use App\Http\Middleware\RequireRole;
use App\Support\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [OriginGuard::class]);
        $middleware->alias([
            'admin' => AdminAuth::class,
            'role' => RequireRole::class,
        ]);
        // The API answers JSON only: no CORS headers, no cookie encryption (tokens are already random), no CSRF tokens (origin check instead).
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $json = fn (array $body, int $status) => response()->json($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $exceptions->render(function (ApiError $e) use ($json) {
            return $json($e->toArray(), $e->status);
        });
        $exceptions->render(function (ValidationException $e, Request $r) use ($json) {
            $fields = array_map(fn ($m) => $m[0], $e->errors());

            return $json(['ok' => false, 'error' => ['code' => 'validation', 'message' => 'Check the fields marked in red.', 'fields' => $fields]], 422);
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($json) {
            if ($r->is('api/*')) {
                return $json(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'No such endpoint.']], 404);
            }
        });
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $r) use ($json) {
            return $json(['ok' => false, 'error' => ['code' => 'method_not_allowed', 'message' => 'Method not allowed.']], 405);
        });
        $exceptions->render(function (\Throwable $e, Request $r) use ($json) {
            if (! $r->is('api/*') || $e instanceof HttpExceptionInterface) {
                return null;
            }
            $body = ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Something went wrong on our side. Please try again.']];
            if (config('app.debug')) {
                $body['error']['debug'] = get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine();
            }

            return $json($body, 500);
        });
    })->create();
