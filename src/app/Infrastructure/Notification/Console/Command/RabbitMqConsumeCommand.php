<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Console\Command;

use App\Infrastructure\Notification\Messaging\ConsumeNotificationJob;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

final class RabbitMqConsumeCommand extends Command
{
    protected $signature = 'rabbitmq:consume {queue}';

    protected $description = 'Consume messages from a RabbitMQ queue';

    private bool $shouldStop = false;

    public function handle(ConsumeNotificationJob $consumer, RabbitMqTopology $topology): int
    {
        $queue = $this->argument('queue');
        $this->info("Starting consumer for queue: {$queue}...");

        // Declare topology first (idempotent)
        $topology->declare();

        $connection = new AMQPStreamConnection(
            config('queue.connections.rabbitmq.hosts.0.host'),
            config('queue.connections.rabbitmq.hosts.0.port'),
            config('queue.connections.rabbitmq.hosts.0.user'),
            config('queue.connections.rabbitmq.hosts.0.password'),
            config('queue.connections.rabbitmq.hosts.0.vhost')
        );

        $channel = $connection->channel();

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () {
                $this->info('SIGTERM received, stopping...');
                $this->shouldStop = true;
            });
            pcntl_signal(SIGINT, function () {
                $this->info('SIGINT received, stopping...');
                $this->shouldStop = true;
            });
        }

        $callback = function (AMQPMessage $message) use ($consumer) {
            try {
                $consumer($message);
            } catch (Throwable $e) {
                $this->error("Error processing message: {$e->getMessage()}");
            }
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        while ($channel->is_consuming() && ! $this->shouldStop) {
            try {
                $channel->wait(null, false, 10);
            } catch (Throwable $e) {
                if (! $this->shouldStop) {
                    $this->error("Error waiting for message: {$e->getMessage()}");
                }
            }
        }

        $channel->close();
        $connection->close();

        $this->info("Consumer for queue {$queue} stopped.");

        return self::SUCCESS;
    }
}
