<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sengaja TIDAK memasang EnsureFrontendRequestsAreStateful di grup `api`.
        // Seluruh aplikasi ini berbicara ke API dengan Bearer token Sanctum
        // (`Authorization: Bearer …`, token dari /auth/login dan /ppdb/auth/register),
        // bukan sesi cookie. Middleware itu justru menandai permintaan dari domain
        // stateful (localhost:5173) sebagai SPA berbasis cookie lalu menurunkan
        // grup `web` lengkap dengan VerifyCsrfToken - sehingga setiap POST/PATCH
        // dari browser mati 419 "CSRF token mismatch" sementara tes HTTP (tanpa
        // header Origin) tetap hijau. Guard `auth:sanctum` di bawah tetap menerima
        // token, dan tanpa cookie tak ada permukaan CSRF yang perlu dijaga.

        // Aplikasi ini SPA tanpa route bernama "login" di sisi Laravel.
        // Tanpa override ini, permintaan guest ke /api/* yang tidak memakai
        // header Accept: application/json akan gagal 500 (Route [login] not
        // defined) alih-alih mengembalikan 401 yang benar.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/login';
        });

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'ctx' => \App\Http\Middleware\RequireContext::class,
            'student.family' => \App\Http\Middleware\RestrictFamilyStudentAccess::class,
        ]);

        // Menegakkan konteks token mobile (wali/siswa dibatasi ke auth/* & mobile/*).
        $middleware->appendToGroup('api', \App\Http\Middleware\EnforceContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Format konsisten untuk error validasi
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data yang diberikan tidak valid',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Unauthenticated
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda belum terautentikasi.',
                ], 401);
            }
        });

        // Model not found
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan.',
                ], 404);
            }
        });

        // Route not found
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = $e->getMessage();

                // Pesan dari `abort(404, '…')` sengaja dibaca klien. Yang dibuang
                // hanya teks internal Symfony/Eloquent ("No query results for
                // model [App\Models\…]", "The route … not found") — lengkap sudah
                // tercatat di log, tidak perlu dibocorkan ke API.
                $internal = $message === ''
                    || str_starts_with($message, 'The route ')
                    || str_contains($message, 'No query results')
                    || str_contains($message, 'App\\Models');

                return response()->json([
                    'success' => false,
                    'message' => $internal ? 'Data atau endpoint tidak ditemukan.' : $message,
                ], 404);
            }
        });

        // Tidak memiliki izin (RBAC)
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki izin untuk melakukan tindakan ini.',
                ], 403);
            }
        });
    })->create();
