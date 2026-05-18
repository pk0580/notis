<?php

declare(strict_types=1);

use App\Interface\Http\Notification\Controller\GetNotificationsByRecipientController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/notifications', GetNotificationsByRecipientController::class);
});
