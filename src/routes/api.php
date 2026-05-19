<?php

declare(strict_types=1);

use App\Interface\Http\Notification\Controller\DispatchNotificationsController;
use App\Interface\Http\Notification\Controller\GetNotificationsByRecipientController;
use App\Interface\Http\Notification\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/notifications', DispatchNotificationsController::class)
        ->middleware([IdempotencyMiddleware::class, 'throttle:60,1']);

    Route::get('/notifications', GetNotificationsByRecipientController::class)
        ->middleware('throttle:120,1');
});

Route::get('/docs', function () {
    return view('swagger');
});

Route::get('/docs/openapi.yaml', function () {
    return response()->file(resource_path('api-docs/openapi.yaml'), [
        'Content-Type' => 'application/yaml',
    ]);
});
