<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\DispatchNotifications;

use App\Application\Notification\Outbox\OutboxEntry;
use App\Application\Notification\Outbox\OutboxRepository;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Event\NotificationQueued;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationId;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

final readonly class DispatchNotificationsAction
{
    public function __construct(
        private NotificationRepository $notifications,
        private OutboxRepository $outbox,
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

    public function handle(DispatchNotificationsData $data): DispatchAcceptedResult
    {
        return $this->db->transaction(function () use ($data) {
            /** @var Notification[] $notifications */
            $notifications = [];
            /** @var OutboxEntry[] $outboxEntries */
            $outboxEntries = [];
            /** @var NotificationId[] $notificationIds */
            $notificationIds = [];

            foreach ($data->recipients as $recipient) {
                $notification = Notification::create(
                    $recipient,
                    $data->channel,
                    $data->priority,
                    $data->body,
                    $data->traceId
                );

                $notifications[] = $notification;
                $outboxEntries[] = new OutboxEntry($notification->id, $notification->priority);
                $notificationIds[] = $notification->id;
            }

            $this->notifications->saveMany(...$notifications);
            $this->outbox->appendMany($outboxEntries);

            $this->db->afterCommit(function () use ($notificationIds) {
                foreach ($notificationIds as $id) {
                    $this->events->dispatch(new NotificationQueued($id));
                }
            });

            return new DispatchAcceptedResult(count($notificationIds), $notificationIds);
        });
    }
}
