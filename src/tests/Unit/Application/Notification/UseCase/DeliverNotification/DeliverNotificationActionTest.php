<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notification\UseCase\DeliverNotification;

use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationAction;
use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationData;
use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationFailedException;
use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationResult;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Exception\GatewayRejectedException;
use App\Domain\Notification\Exception\GatewayUnavailableException;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Gateway\SendResult;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Domain\Notification\ValueObject\Recipient;
use Illuminate\Contracts\Bus\Dispatcher;
use Mockery;
use Tests\TestCase;

class DeliverNotificationActionTest extends TestCase
{
    private NotificationRepository $notificationRepository;
    private NotificationGateway $gateway;
    private Dispatcher $bus;
    private DeliverNotificationAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationRepository = new class implements NotificationRepository {
            public array $notifications = [];
            public function save(Notification $notification): void { $this->notifications[$notification->id->value] = $notification; }
            public function saveMany(array $notifications): void { foreach ($notifications as $n) { $this->save($n); } }
            public function findById(NotificationId $id): ?Notification { return $this->notifications[$id->value] ?? null; }
            public function findByRecipient(string $recipient, int $limit): array { return []; }
        };

        $this->gateway = Mockery::mock(NotificationGateway::class);
        $this->bus = Mockery::mock(Dispatcher::class);

        $this->action = new DeliverNotificationAction(
            $this->notificationRepository,
            $this->gateway,
            $this->bus
        );
    }

    public function test_it_delivers_successfully(): void
    {
        $notification = Notification::create(
            Recipient::fromString(Channel::Sms, '+1234567890'),
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body')
        );
        $this->notificationRepository->save($notification);

        $data = new DeliverNotificationData($notification->id);

        $this->gateway->shouldReceive('send')
            ->once()
            ->with($notification)
            ->andReturn(new SendResult(new ProviderMessageId('pid-123')));

        $this->bus->shouldReceive('dispatch')->once();

        $result = $this->action->handle($data);

        $this->assertEquals(DeliverNotificationResult::Success, $result);
        $this->assertEquals(NotificationStatus::Sent, $notification->status());
        $this->assertEquals('pid-123', $notification->providerMessageId()->value);
    }

    public function test_it_returns_noop_if_not_queued(): void
    {
        $notification = Notification::create(
            Recipient::fromString(Channel::Sms, '+1234567890'),
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body')
        );
        $notification->markAsSent(new ProviderMessageId('pid-123'));
        $this->notificationRepository->save($notification);

        $data = new DeliverNotificationData($notification->id);

        $this->gateway->shouldReceive('send')->never();

        $result = $this->action->handle($data);

        $this->assertEquals(DeliverNotificationResult::NoOp, $result);
    }

    public function test_it_records_failed_attempt_on_transient_error(): void
    {
        $notification = Notification::create(
            Recipient::fromString(Channel::Sms, '+1234567890'),
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body')
        );
        $this->notificationRepository->save($notification);

        $data = new DeliverNotificationData($notification->id);

        $this->gateway->shouldReceive('send')
            ->once()
            ->andThrow(new GatewayUnavailableException('Gateway down'));

        $this->expectException(DeliverNotificationFailedException::class);
        $this->expectExceptionMessage('Gateway down');

        try {
            $this->action->handle($data);
        } catch (DeliverNotificationFailedException $e) {
            $this->assertEquals(1, $notification->attempts());
            $this->assertEquals('Gateway down', $notification->lastError());
            $this->assertEquals(NotificationStatus::Queued, $notification->status());
            throw $e;
        }
    }

    public function test_it_marks_as_dropped_on_permanent_error(): void
    {
        $notification = Notification::create(
            Recipient::fromString(Channel::Sms, '+1234567890'),
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body')
        );
        $this->notificationRepository->save($notification);

        $data = new DeliverNotificationData($notification->id);

        $this->gateway->shouldReceive('send')
            ->once()
            ->andThrow(new GatewayRejectedException('Invalid content'));

        try {
            $this->action->handle($data);
            $this->fail('Should have thrown PermanentDeliverNotificationFailedException');
        } catch (\App\Application\Notification\UseCase\DeliverNotification\PermanentDeliverNotificationFailedException $e) {
            $this->assertEquals('Invalid content', $e->getMessage());
        }

        $this->assertEquals(1, $notification->attempts());
        $this->assertEquals('Invalid content', $notification->lastError());
        $this->assertEquals(NotificationStatus::Dropped, $notification->status());
        $history = $notification->history()->items;
        $lastEntry = end($history);
        $this->assertStringContainsString('provider_rejected', $lastEntry['reason']);
    }
}
