<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tests\TestCase;

abstract class RabbitMqIntegrationTestCase extends TestCase
{
    protected AMQPStreamConnection $rabbitmqConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('queue.connections.rabbitmq.hosts.0');
        $this->rabbitmqConnection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'] ?? '/'
        );

        $this->purgeQueues();
    }

    protected function tearDown(): void
    {
        if (isset($this->rabbitmqConnection)) {
            $this->rabbitmqConnection->close();
        }

        parent::tearDown();
    }

    protected function purgeQueues(): void
    {
        $channel = $this->rabbitmqConnection->channel();
        
        $queues = [
            RabbitMqTopology::QUEUE_TRANSACTIONAL,
            RabbitMqTopology::QUEUE_MARKETING,
            RabbitMqTopology::QUEUE_TRANSACTIONAL_RETRY,
            RabbitMqTopology::QUEUE_MARKETING_RETRY,
            RabbitMqTopology::QUEUE_DLQ,
        ];

        foreach ($queues as $queue) {
            $channel->queue_purge($queue);
        }

        $channel->close();
    }
}
