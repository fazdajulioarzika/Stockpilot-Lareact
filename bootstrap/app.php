<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with('error', 'Sesi Anda telah berakhir. Muat ulang halaman lalu coba lagi.');
            }

            // Saat pengembangan, 500 dan 503 tetap memakai halaman debug Laravel.
            $handled = app()->environment(['local', 'testing']) ? [403, 404] : [403, 404, 500, 503];

            // Hanya user login dan route yang cocok, supaya data bersama Inertia (user, menu) pasti ada.
            if ($request->user() && $request->route() !== null && in_array($status, $handled, true)) {
                /** @var \Symfony\Component\HttpFoundation\Response $page */
                $page = \Inertia\Inertia::render('error', ['status' => $status])->toResponse($request);
                $page->setStatusCode($status);

                return $page;
            }

            return $response;
        });
    })->create();
