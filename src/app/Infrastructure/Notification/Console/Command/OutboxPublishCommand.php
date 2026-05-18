<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Console\Command;

use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use Illuminate\Console\Command;
use Throwable;

final class OutboxPublishCommand extends Command
{
    protected $signature = 'outbox:publish {--loop : Run in a continuous loop} {--batch=100 : Batch size}';

    protected $description = 'Publish messages from outbox to RabbitMQ';

    private bool $shouldStop = false;

    public function handle(OutboxPublisher $publisher, RabbitMqTopology $topology): int
    {
        $this->info('Starting outbox publisher...');

        // Declare topology first (idempotent)
        $topology->declare();

        if (!$this->option('loop')) {
            $count = $publisher->flush((int) $this->option('batch'));
            $this->info("Published {$count} messages.");
            return self::SUCCESS;
        }

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

        while (!$this->shouldStop) {
            try {
                $count = $publisher->flush((int) $this->option('batch'));
                
                if ($count === 0) {
                    usleep(500_000); // 500ms
                } else {
                    $this->info("Published {$count} messages.");
                }
            } catch (Throwable $e) {
                $this->error("Error publishing: {$e->getMessage()}");
                sleep(1); // Wait a bit before retry
            }
        }

        $this->info('Outbox publisher stopped.');

        return self::SUCCESS;
    }
}
