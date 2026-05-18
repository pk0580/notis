<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Job;

use App\Application\Notification\UseCase\AcknowledgeDelivery\AcknowledgeDeliveryAction;
use App\Application\Notification\UseCase\AcknowledgeDelivery\AcknowledgeDeliveryData;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SimulateDeliveryAckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $notificationId
    ) {}

    public function handle(AcknowledgeDeliveryAction $action): void
    {
        // 90% → Delivered, 10% → Dropped (§6.4)
        $chance = random_int(1, 100);

        if ($chance <= 90) {
            $finalStatus = NotificationStatus::Delivered;
            $reason = null;
        } else {
            $finalStatus = NotificationStatus::Dropped;
            $reason = 'provider_rejected_late: delivery_failed';
        }

        $action->handle(new AcknowledgeDeliveryData(
            new NotificationId($this->notificationId),
            $finalStatus,
            $reason
        ));
    }
}
