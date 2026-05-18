<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Messaging;

use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class OutboxPublisher
{
    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost = '/',
    ) {}

    /**
     * @param int $batchSize
     * @return int Number of published messages
     */
    public function flush(int $batchSize = 100): int
    {
        return DB::transaction(function () use ($batchSize) {
            $rows = DB::table('outbox_messages as o')
                ->join('notifications as n', 'n.id', '=', 'o.notification_id')
                ->whereNull('o.published_at')
                ->orderBy('o.created_at')
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->select([
                    'o.id as outbox_id',
                    'o.notification_id',
                    'o.priority',
                    'n.trace_id'
                ])
                ->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost
            );
            $channel = $connection->channel();

            foreach ($rows as $row) {
                $payload = json_encode([
                    'notification_id' => $row->notification_id,
                ], JSON_THROW_ON_ERROR);

                $headers = [
                    'x-retries' => 0,
                ];

                if ($row->trace_id !== null) {
                    $headers['x-trace-id'] = $row->trace_id;
                }

                $msg = new AMQPMessage($payload, [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json',
                ]);
                $msg->set('application_headers', new AMQPTable($headers));

                $channel->basic_publish(
                    $msg,
                    RabbitMqTopology::EXCHANGE_DIRECT,
                    $row->priority
                );

                DB::table('outbox_messages')
                    ->where('id', $row->outbox_id)
                    ->update(['published_at' => now()]);
            }

            $channel->close();
            $connection->close();

            return $rows->count();
        });
    }
}
