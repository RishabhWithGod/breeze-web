<?php

use App\Http\Middleware\BlockApprenticeAccess;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'block.apprentice' => BlockApprenticeAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render HTTP errors through the React state screens, so a 404 or a
        // failed action still arrives inside the app shell.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // Every mobile endpoint speaks one JSON envelope
            // (`{success, message, data}`), including its errors — never
            // Laravel's default per-exception shape, and never a 200 for a
            // failure that reached this handler at all.
            if ($request->is('api/*')) {
                return match (true) {
                    $exception instanceof ValidationException => response()->json([
                        'success' => false,
                        'message' => $exception->getMessage(),
                        'errors' => $exception->errors(),
                    ], 422),
                    $exception instanceof AuthenticationException => response()->json([
                        'success' => false,
                        'message' => 'Authentication required.',
                        'errors' => null,
                    ], 401),
                    $exception instanceof AuthorizationException => response()->json([
                        'success' => false,
                        'message' => $exception->getMessage() ?: 'This action is unauthorized.',
                        'errors' => null,
                    ], 403),
                    $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => response()->json([
                        'success' => false,
                        'message' => 'The requested resource was not found.',
                        'errors' => null,
                    ], 404),
                    default => response()->json([
                        'success' => false,
                        'message' => $response->getStatusCode() >= 500
                            ? 'Something went wrong. Please try again.'
                            : ($exception->getMessage() ?: 'The request could not be completed.'),
                        'errors' => null,
                    ], $response->getStatusCode()),
                };
            }

            if (! $request->header('X-Inertia')) {
                return $response;
            }

            if ($response->getStatusCode() === 419) {
                return back()->with('warning', 'Your session expired — please try again.');
            }

            if (in_array($response->getStatusCode(), [403, 404, 500, 503], true)) {
                return Inertia::render(
                    $response->getStatusCode() === 404 ? 'NotFound' : 'ErrorState',
                    ['status' => $response->getStatusCode()],
                )
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
