<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notification\UseCase\AcknowledgeDelivery;

use App\Application\Notification\UseCase\AcknowledgeDelivery\AcknowledgeDeliveryAction;
use App\Application\Notification\UseCase\AcknowledgeDelivery\AcknowledgeDeliveryData;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Domain\Notification\ValueObject\Recipient;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class AcknowledgeDeliveryActionTest extends MockeryTestCase
{
    private NotificationRepository $notificationRepository;

    private AcknowledgeDeliveryAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationRepository = new class implements NotificationRepository
        {
            public array $notifications = [];

            public function save(Notification $notification): void
            {
                $this->notifications[$notification->id->value] = $notification;
            }

            public function saveMany(array $notifications): void
            {
                foreach ($notifications as $n) {
                    $this->save($n);
                }
            }

            public function findById(NotificationId $id): ?Notification
            {
                return $this->notifications[$id->value] ?? null;
            }

            public function findByRecipient(string $recipient, int $limit): array
            {
                return [];
            }
        };

        $this->action = new AcknowledgeDeliveryAction($this->notificationRepository);
    }

    public function test_it_acknowledges_delivery_successfully(): void
    {
        $notification = Notification::create(
            Recipient::fromString(Channel::Sms, '+1234567890'),
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body')
        );
        $notification->markAsSent(new ProviderMessageId('pid-123'));
        $this->notificationRepository->save($notification);

        $data = new AcknowledgeDeliveryData($notification->id, NotificationStatus::Delivered);

        $this->action->handle($data);

        $this->assertEquals(NotificationStatus::Delivered, $notification->status());
    }

    public function test_it_acknowledges_dropped_successfully(): void
    {
        $notification = Notification::create(
            Recipient::fromString(Channel::Sms, '+1234567890'),
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body')
        );
        $notification->markAsSent(new ProviderMessageId('pid-123'));
        $this->notificationRepository->save($notification);

        $data = new AcknowledgeDeliveryData($notification->id, NotificationStatus::Dropped, 'Late rejection');

        $this->action->handle($data);

        $this->assertEquals(NotificationStatus::Dropped, $notification->status());
        $history = $notification->history()->items;
        $lastEntry = end($history);
        $this->assertEquals('Late rejection', $lastEntry['reason']);
    }

    public function test_it_is_idempotent(): void
    {
        $notification = Notification::create(
            Recipient::fromString(Channel::Sms, '+1234567890'),
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body')
        );
        $notification->markAsSent(new ProviderMessageId('pid-123'));
        $notification->markAsDelivered();
        $this->notificationRepository->save($notification);

        $data = new AcknowledgeDeliveryData($notification->id, NotificationStatus::Dropped, 'Should be ignored');

        $this->action->handle($data);

        $this->assertEquals(NotificationStatus::Delivered, $notification->status());
    }
}
