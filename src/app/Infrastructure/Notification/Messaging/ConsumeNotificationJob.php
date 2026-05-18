<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Messaging;

use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationAction;
use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationData;
use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationFailedException;
use App\Application\Notification\UseCase\DeliverNotification\PermanentDeliverNotificationFailedException;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationId;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final readonly class ConsumeNotificationJob
{
    public function __construct(
        private DeliverNotificationAction $deliverAction,
        private NotificationRepository $repository,
        private array $retryBackoffMs,
        private int $maxAttempts = 5,
    ) {}

    public function __invoke(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $notificationId = new NotificationId($payload['notification_id']);

        $headers = [];
        if ($message->has('application_headers')) {
            $headers = $message->get('application_headers')->getNativeData();
        }

        $xRetries = (int) ($headers['x-retries'] ?? 0);
        $xTraceId = (string) ($headers['x-trace-id'] ?? '');

        Log::withContext([
            'notification_id' => $notificationId->value,
            'trace_id' => $xTraceId,
            'x_retries' => $xRetries,
        ]);

        try {
            $this->deliverAction->handle(new DeliverNotificationData($notificationId));
            $message->ack();
        } catch (DeliverNotificationFailedException $e) {
            $this->handleRetry($message, $notificationId, $xRetries, $xTraceId, $e->getMessage());
        } catch (PermanentDeliverNotificationFailedException $e) {
            $this->moveToDlq($message, $notificationId, $xTraceId, "Permanent failure: {$e->getMessage()}");
        } catch (Throwable $e) {
            Log::error("Permanent failure for notification {$notificationId->value}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            $message->ack(); // Ack to remove from main queue, it's already marked as dropped or failed in Action
        }
    }

    private function handleRetry(
        AMQPMessage $message,
        NotificationId $notificationId,
        int $xRetries,
        string $xTraceId,
        string $errorMessage
    ): void {
        $nextRetry = $xRetries + 1;

        if ($nextRetry >= $this->maxAttempts) {
            $this->moveToDlq($message, $notificationId, $xTraceId, "Max retries exceeded: {$errorMessage}");

            return;
        }

        $delayMs = $this->retryBackoffMs[$xRetries] ?? end($this->retryBackoffMs);

        $channel = $message->getChannel();
        $priority = $message->getRoutingKey();

        $retryHeaders = [
            'x-retries' => $nextRetry,
        ];
        if ($xTraceId !== '') {
            $retryHeaders['x-trace-id'] = $xTraceId;
        }

        $retryMsg = new AMQPMessage($message->getBody(), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
            'expiration' => (string) $delayMs,
        ]);
        $retryMsg->set('application_headers', new AMQPTable($retryHeaders));

        $channel->basic_publish(
            $retryMsg,
            RabbitMqTopology::EXCHANGE_RETRY,
            $priority
        );

        $message->ack();

        Log::info("Notification {$notificationId->value} scheduled for retry #{$nextRetry} in {$delayMs}ms");
    }

    private function moveToDlq(
        AMQPMessage $message,
        NotificationId $notificationId,
        string $xTraceId,
        string $reason
    ): void {
        $channel = $message->getChannel();

        $dlqHeaders = [
            'x-trace-id' => $xTraceId,
            'x-death-reason' => $reason,
        ];

        $dlqMsg = new AMQPMessage($message->getBody(), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);
        $dlqMsg->set('application_headers', new AMQPTable($dlqHeaders));

        $channel->basic_publish(
            $dlqMsg,
            RabbitMqTopology::EXCHANGE_DLQ,
            $message->getRoutingKey()
        );

        $message->ack();

        Log::warning("Notification {$notificationId->value} moved to DLQ: {$reason}");

        // Also mark as dropped in DB if not already done
        $notification = $this->repository->findById($notificationId);
        if ($notification && $notification->status()->value !== 'dropped') {
            $notification->markAsDropped('max_retries_exceeded');
            $this->repository->save($notification);
        }
    }
}
