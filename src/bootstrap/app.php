<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Infrastructure\Notification\Provider\NotificationServiceProvider::class,
    ])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Infrastructure\Notification\Http\Middleware\TraceIdMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Domain\Notification\Exception\InvalidRecipientException $e) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_recipient',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Notification\Exception\InvalidMessageBodyException $e) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_message_body',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Notification\Exception\InvalidNotificationStatusTransitionException $e) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_status_transition',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        });
    })->create();
