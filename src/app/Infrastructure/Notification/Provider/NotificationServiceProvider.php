<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Provider;

use App\Application\Notification\Outbox\OutboxRepository;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Infrastructure\Notification\Persistence\Eloquent\Repositories\EloquentNotificationRepository;
use App\Infrastructure\Notification\Persistence\Eloquent\Repositories\EloquentOutboxRepository;
use Illuminate\Support\ServiceProvider;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationRepository::class, EloquentNotificationRepository::class);
        $this->app->bind(OutboxRepository::class, EloquentOutboxRepository::class);
    }
}
