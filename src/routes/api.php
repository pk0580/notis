<?php

declare(strict_types=1);

use App\Interface\Http\Notification\Controller\DispatchNotificationsController;
use App\Interface\Http\Notification\Controller\GetNotificationsByRecipientController;
use App\Interface\Http\Notification\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/notifications', DispatchNotificationsController::class)
        ->middleware(IdempotencyMiddleware::class);

    Route::get('/notifications', GetNotificationsByRecipientController::class);
});
