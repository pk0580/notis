<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notification\UseCase\DispatchNotifications;

use App\Application\Notification\Outbox\OutboxRepository;
use App\Application\Notification\UseCase\DispatchNotifications\DispatchNotificationsAction;
use App\Application\Notification\UseCase\DispatchNotifications\DispatchNotificationsData;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Event\NotificationQueued;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\Recipient;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class DispatchNotificationsActionTest extends MockeryTestCase
{
    private NotificationRepository $notificationRepository;

    private OutboxRepository $outboxRepository;

    private DatabaseManager $databaseManager;

    private Dispatcher $dispatcher;

    private DispatchNotificationsAction $action;

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

            public function saveMany(Notification ...$notifications): void
            {
                foreach ($notifications as $n) {
                    $this->save($n);
                }
            }

            public function findById(NotificationId $id): ?Notification
            {
                return $this->notifications[$id->value] ?? null;
            }
        };

        $this->outboxRepository = new class implements OutboxRepository
        {
            public array $entries = [];

            public function appendMany(array $entries): void
            {
                $this->entries = array_merge($this->entries, $entries);
            }
        };

        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->dispatcher = Mockery::mock(Dispatcher::class);

        $this->action = new DispatchNotificationsAction(
            $this->notificationRepository,
            $this->outboxRepository,
            $this->databaseManager,
            $this->dispatcher
        );
    }

    public function test_it_dispatches_notifications_successfully(): void
    {
        $data = new DispatchNotificationsData(
            Channel::Sms,
            Priority::Transactional,
            MessageBody::for(Channel::Sms, 'Test body'),
            [Recipient::fromString(Channel::Sms, '+1234567890'), Recipient::fromString(Channel::Sms, '+1987654321')],
            'trace-id'
        );

        $this->databaseManager->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());

        $this->databaseManager->shouldReceive('afterCommit')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->with(Mockery::type(NotificationQueued::class));

        $result = $this->action->handle($data);

        $this->assertEquals(2, $result->accepted);
        $this->assertCount(2, $result->notificationIds);
        $this->assertCount(2, $this->notificationRepository->notifications);
        $this->assertCount(2, $this->outboxRepository->entries);

        foreach ($this->notificationRepository->notifications as $notification) {
            $this->assertEquals(Channel::Sms, $notification->channel);
            $this->assertEquals(Priority::Transactional, $notification->priority);
            $this->assertEquals('Test body', $notification->body->value);
            $this->assertEquals('trace-id', $notification->traceId);
        }
    }
}
