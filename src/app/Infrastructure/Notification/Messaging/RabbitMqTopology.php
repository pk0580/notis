<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqTopology
{
    public const EXCHANGE_DIRECT = 'notifications.direct';
    public const EXCHANGE_RETRY = 'notifications.retry';
    public const EXCHANGE_DLQ = 'notifications.dlq';

    public const QUEUE_TRANSACTIONAL = 'notifications.transactional';
    public const QUEUE_MARKETING = 'notifications.marketing';
    public const QUEUE_TRANSACTIONAL_RETRY = 'notifications.transactional.retry';
    public const QUEUE_MARKETING_RETRY = 'notifications.marketing.retry';
    public const QUEUE_DLQ = 'notifications.dlq';

    public const ROUTING_KEY_TRANSACTIONAL = 'transactional';
    public const ROUTING_KEY_MARKETING = 'marketing';

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost = '/',
    ) {}

    public function declare(): void
    {
        $connection = new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->vhost
        );
        $channel = $connection->channel();

        // 1. Exchanges
        $channel->exchange_declare(static::EXCHANGE_DIRECT, AMQPExchangeType::DIRECT, false, true, false);
        $channel->exchange_declare(static::EXCHANGE_RETRY, AMQPExchangeType::DIRECT, false, true, false);
        $channel->exchange_declare(static::EXCHANGE_DLQ, AMQPExchangeType::TOPIC, false, true, false);

        // 2. Main Queues with DLX pointing to Retry Exchange
        $channel->queue_declare(
            static::QUEUE_TRANSACTIONAL,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => static::EXCHANGE_RETRY,
                'x-dead-letter-routing-key' => static::ROUTING_KEY_TRANSACTIONAL,
            ])
        );

        $channel->queue_declare(
            static::QUEUE_MARKETING,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => static::EXCHANGE_RETRY,
                'x-dead-letter-routing-key' => static::ROUTING_KEY_MARKETING,
            ])
        );

        // 3. Bindings for Main Queues
        $channel->queue_bind(static::QUEUE_TRANSACTIONAL, static::EXCHANGE_DIRECT, static::ROUTING_KEY_TRANSACTIONAL);
        $channel->queue_bind(static::QUEUE_MARKETING, static::EXCHANGE_DIRECT, static::ROUTING_KEY_MARKETING);

        // 4. Retry Queues with DLX pointing back to Direct Exchange
        $channel->queue_declare(
            static::QUEUE_TRANSACTIONAL_RETRY,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => static::EXCHANGE_DIRECT,
                'x-dead-letter-routing-key' => static::ROUTING_KEY_TRANSACTIONAL,
            ])
        );

        $channel->queue_declare(
            static::QUEUE_MARKETING_RETRY,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => static::EXCHANGE_DIRECT,
                'x-dead-letter-routing-key' => static::ROUTING_KEY_MARKETING,
            ])
        );

        // 5. Bindings for Retry Queues
        $channel->queue_bind(static::QUEUE_TRANSACTIONAL_RETRY, static::EXCHANGE_RETRY, static::ROUTING_KEY_TRANSACTIONAL);
        $channel->queue_bind(static::QUEUE_MARKETING_RETRY, static::EXCHANGE_RETRY, static::ROUTING_KEY_MARKETING);

        // 6. DLQ
        $channel->queue_declare(static::QUEUE_DLQ, false, true, false, false);
        $channel->queue_bind(static::QUEUE_DLQ, static::EXCHANGE_DLQ, '#'); // Bind everything for DLQ direct

        $channel->close();
        $connection->close();
    }
}
