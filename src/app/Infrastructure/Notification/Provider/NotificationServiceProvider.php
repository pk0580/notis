<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Provider;

use App\Application\Notification\Idempotency\IdempotencyStore;
use App\Application\Notification\Outbox\OutboxRepository;
use App\Application\Notification\ReadRepository\NotificationReadRepository;
use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationAction;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Infrastructure\Notification\Console\Command\OutboxPublishCommand;
use App\Infrastructure\Notification\Console\Command\OutboxPurgeCommand;
use App\Infrastructure\Notification\Console\Command\RabbitMqConsumeCommand;
use App\Infrastructure\Notification\Gateway\CompositeNotificationGateway;
use App\Infrastructure\Notification\Gateway\StubEmailGateway;
use App\Infrastructure\Notification\Gateway\StubSmsGateway;
use App\Infrastructure\Notification\Idempotency\RedisIdempotencyStore;
use App\Infrastructure\Notification\Messaging\ConsumeNotificationJob;
use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use App\Infrastructure\Notification\Persistence\Eloquent\Repositories\EloquentNotificationReadRepository;
use App\Infrastructure\Notification\Persistence\Eloquent\Repositories\EloquentNotificationRepository;
use App\Infrastructure\Notification\Persistence\Eloquent\Repositories\EloquentOutboxRepository;
use Illuminate\Support\ServiceProvider;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationRepository::class, EloquentNotificationRepository::class);
        $this->app->bind(OutboxRepository::class, EloquentOutboxRepository::class);
        $this->app->bind(NotificationReadRepository::class, EloquentNotificationReadRepository::class);

        $this->app->singleton(IdempotencyStore::class, RedisIdempotencyStore::class);

        $this->app->singleton(NotificationGateway::class, function ($app) {
            return new CompositeNotificationGateway([
                new StubSmsGateway,
                new StubEmailGateway,
            ]);
        });

        $this->app->singleton(RabbitMqTopology::class, function ($app) {
            $config = config('queue.connections.rabbitmq.hosts.0');

            return new RabbitMqTopology(
                $config['host'],
                (int) $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost'] ?? '/'
            );
        });

        $this->app->singleton(OutboxPublisher::class, function ($app) {
            $config = config('queue.connections.rabbitmq.hosts.0');

            return new OutboxPublisher(
                $config['host'],
                (int) $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost'] ?? '/'
            );
        });

        $this->app->singleton(ConsumeNotificationJob::class, function ($app) {
            return new ConsumeNotificationJob(
                $app->make(DeliverNotificationAction::class),
                $app->make(NotificationRepository::class),
                array_map('intval', config('notifications.retry_backoff_ms')),
                (int) config('notifications.max_attempts')
            );
        });

        $this->commands([
            OutboxPublishCommand::class,
            OutboxPurgeCommand::class,
            RabbitMqConsumeCommand::class,
        ]);
    }
}
