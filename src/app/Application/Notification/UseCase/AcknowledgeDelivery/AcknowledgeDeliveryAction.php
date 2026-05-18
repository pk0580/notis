<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\AcknowledgeDelivery;

use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationStatus;

final readonly class AcknowledgeDeliveryAction
{
    public function __construct(
        private NotificationRepository $notifications,
    ) {}

    public function handle(AcknowledgeDeliveryData $data): void
    {
        $notification = $this->notifications->findById($data->notificationId);

        if ($notification === null) {
            return;
        }

        $currentStatus = $notification->status();
        if ($currentStatus === NotificationStatus::Delivered || $currentStatus === NotificationStatus::Dropped) {
            return;
        }

        if ($data->finalStatus === NotificationStatus::Delivered) {
            $notification->markAsDelivered();
        } elseif ($data->finalStatus === NotificationStatus::Dropped) {
            $notification->markAsDropped($data->reason ?? 'unknown_reason');
        } else {
            // Only Delivered or Dropped are expected as final status
            return;
        }

        $this->notifications->save($notification);
    }
}
