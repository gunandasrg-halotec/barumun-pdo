<?php

use App\Http\Middleware\EnsureUnitAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // ── Alias middleware ──────────────────────────────
        $middleware->alias([
            'ensure.unit.access' => EnsureUnitAccess::class,
        ]);

        // ── Sanctum stateful domains (untuk SPA cookie auth) ──
        $middleware->statefulApi();

        // ── API global middleware ─────────────────────────
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ── 401: Unauthenticated → JSON response ──────────
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'TOKEN_INVALID',
                        'message' => 'Token tidak valid atau sudah kedaluwarsa. Silakan login kembali.',
                    ],
                ], 401);
            }
        });

        // ── 422: Validation → JSON response standar ───────
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                $details = collect($e->errors())
                    ->flatMap(fn($messages, $field) => collect($messages)->map(fn($message) => [
                        'field'   => $field,
                        'message' => $message,
                    ]))
                    ->values()
                    ->toArray();

                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'VALIDATION_ERROR',
                        'message' => 'Data tidak valid.',
                        'details' => $details,
                    ],
                ], 422);
            }
        });

        // ── 404: Model Not Found ───────────────────────────
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'NOT_FOUND',
                        'message' => 'Data tidak ditemukan.',
                    ],
                ], 404);
            }
        });

        // ── 403: Authorization ─────────────────────────────
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'FORBIDDEN',
                        'message' => 'Anda tidak memiliki izin untuk melakukan aksi ini.',
                    ],
                ], 403);
            }
        });

    })->create();
