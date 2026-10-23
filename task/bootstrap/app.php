<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'submissions',
            'forms/*',
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (BadRequestHttpException $e, Request $request) {
            return response()->json([
                'message' => 'Bad request: ' . $e->getMessage(),
            ], 400);
        });

        $exceptions->render(function (\JsonException $e, Request $request) {
            return response()->json([
                'message' => 'Invalid JSON payload: ' . $e->getMessage(),
            ], 400);
        });
    })->create();
