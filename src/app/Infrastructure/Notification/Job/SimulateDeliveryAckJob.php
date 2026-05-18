<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Job;

use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationId;
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
    ) {
        // Используем стандартную очередь для имитации колбэков
        $this->queue = 'default';
    }

    public function handle(NotificationRepository $repository): void
    {
        $notification = $repository->findById(new NotificationId($this->notificationId));

        if ($notification === null) {
            return;
        }

        // Имитируем задержку провайдера (уже обеспечено очередью)
        // С вероятностью 95% - доставлено, 5% - dropped (например, неверный номер)
        if (random_int(1, 100) <= 95) {
            $notification->markAsDelivered();
        } else {
            $notification->markAsDropped('provider_feedback: delivery_failed');
        }

        $repository->save($notification);
    }
}
