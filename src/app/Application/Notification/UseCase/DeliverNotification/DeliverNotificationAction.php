<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\DeliverNotification;

use App\Domain\Notification\Exception\GatewayRejectedException;
use App\Domain\Notification\Exception\GatewayTimeoutException;
use App\Domain\Notification\Exception\GatewayUnavailableException;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationStatus;
use Throwable;

final readonly class DeliverNotificationAction
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationGateway $gateway,
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

            return DeliverNotificationResult::Success;
        } catch (GatewayUnavailableException | GatewayTimeoutException $e) {
            $notification->recordFailedAttempt($e->getMessage());
            $this->notifications->save($notification);

            throw new DeliverNotificationFailedException($e->getMessage(), (int) $e->getCode(), $e);
        } catch (GatewayRejectedException $e) {
            $notification->recordFailedAttempt($e->getMessage());
            $notification->markAsDropped('provider_rejected: ' . $e->getMessage());
            $this->notifications->save($notification);

            return DeliverNotificationResult::Success; // It is "handled" even if dropped
        } catch (Throwable $e) {
            // General failure
            $notification->recordFailedAttempt($e->getMessage());
            $this->notifications->save($notification);

            throw new DeliverNotificationFailedException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
