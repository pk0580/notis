<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Messaging;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class OutboxPublisher
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost = '/',
    ) {}

    /**
     * @return int Number of published messages
     */
    public function flush(int $batchSize = 100): int
    {
        $reservedIds = $this->reserveBatch($batchSize);

        if (empty($reservedIds)) {
            return 0;
        }

        $messages = $this->getMessages($reservedIds);

        try {
            $this->publish($messages);
            $this->completeBatch($reservedIds);
        } catch (Throwable $e) {
            $this->failBatch($reservedIds, $e);
            throw $e;
        }

        return $messages->count();
    }

    /**
     * @return array<string>
     */
    private function reserveBatch(int $batchSize): array
    {
        return DB::transaction(function () use ($batchSize) {
            $ids = DB::table('outbox_messages')
                ->whereNull('published_at')
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('reserved_at')
                            ->where(function ($q2) {
                                $q2->whereNull('available_at')
                                    ->orWhere('available_at', '<=', now());
                            });
                    })
                        ->orWhere('reserved_at', '<', now()->subMinutes(5));
                })
                ->orderBy('created_at')
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->pluck('id')
                ->toArray();

            if (empty($ids)) {
                return [];
            }

            DB::table('outbox_messages')
                ->whereIn('id', $ids)
                ->update([
                    'reserved_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                ]);

            return $ids;
        });
    }

    private function getMessages(array $reservedIds): Collection
    {
        return DB::table('outbox_messages as o')
            ->join('notifications as n', 'n.id', '=', 'o.notification_id')
            ->whereIn('o.id', $reservedIds)
            ->select([
                'o.id as outbox_id',
                'o.notification_id',
                'o.priority',
                'n.trace_id',
            ])
            ->get();
    }

    private function publish(Collection $messages): void
    {
        $connection = null;
        try {
            $connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost
            );
            $channel = $connection->channel();
            $channel->confirm_select();

            foreach ($messages as $message) {
                $this->publishMessage($channel, $message);
            }

            $channel->wait_for_pending_acks(5.0);
            $channel->close();
        } finally {
            if ($connection?->isConnected()) {
                $connection->close();
            }
        }
    }

    private function publishMessage(AMQPChannel $channel, object $row): void
    {
        $payload = json_encode([
            'notification_id' => $row->notification_id,
        ], JSON_THROW_ON_ERROR);

        $headers = ['x-retries' => 0];
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
    }

    private function completeBatch(array $reservedIds): void
    {
        DB::table('outbox_messages')
            ->whereIn('id', $reservedIds)
            ->update([
                'published_at' => now(),
                'reserved_at' => null,
            ]);
    }

    private function failBatch(array $reservedIds, Throwable $e): void
    {
        DB::table('outbox_messages')
            ->whereIn('id', $reservedIds)
            ->update([
                'reserved_at' => null,
                'available_at' => DB::raw("
                    CASE 
                        WHEN attempts = 1 THEN now() + interval '1 minute'
                        WHEN attempts = 2 THEN now() + interval '5 minutes'
                        WHEN attempts = 3 THEN now() + interval '15 minutes'
                        ELSE now() + interval '1 hour'
                    END
                "),
                'last_error' => $e->getMessage(),
            ]);
    }
}
