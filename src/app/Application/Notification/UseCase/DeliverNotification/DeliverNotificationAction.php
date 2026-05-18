<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\DeliverNotification;

use App\Domain\Notification\Exception\GatewayRejectedException;
use App\Domain\Notification\Exception\GatewayTimeoutException;
use App\Domain\Notification\Exception\GatewayUnavailableException;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Infrastructure\Notification\Job\SimulateDeliveryAckJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

final readonly class DeliverNotificationAction
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationGateway $gateway,
        private Dispatcher $bus,
    ) {}

    public function handle(DeliverNotificationData $data): DeliverNotificationResult
    {
        $notification = $this->notifications->findById($data->notificationId);

        if ($notification === null) {
            return DeliverNotificationResult::NoOp;
        }

        if ($notification->status() !== NotificationStatus::Queued) {
            return DeliverNotificationResult::NoOp;
        }

        try {
            $result = $this->gateway->send($notification);
            $notification->markAsSent($result->messageId);
            $this->notifications->save($notification);

            // Имитируем асинхронный колбэк о доставке на database queue (A4, §6.4)
            $this->bus->dispatch(
                (new SimulateDeliveryAckJob($notification->id->value))
                    ->onConnection(config('notifications.queue_connections.delivery_ack', 'database'))
                    ->onQueue('default')
                    ->delay(now()->addSeconds(random_int(1, 3)))
            );

            return DeliverNotificationResult::Success;
        } catch (GatewayUnavailableException|GatewayTimeoutException $e) {
            $notification->recordFailedAttempt($e->getMessage());
            $this->notifications->save($notification);

            throw new DeliverNotificationFailedException($e->getMessage(), (int) $e->getCode(), $e);
        } catch (GatewayRejectedException $e) {
            $notification->recordFailedAttempt($e->getMessage());
            $notification->markAsDropped('provider_rejected: '.$e->getMessage());
            $this->notifications->save($notification);

            throw new PermanentDeliverNotificationFailedException($e->getMessage(), (int) $e->getCode(), $e);
        } catch (Throwable $e) {
            // General failure
            $notification->recordFailedAttempt($e->getMessage());
            $this->notifications->save($notification);

            throw new DeliverNotificationFailedException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
