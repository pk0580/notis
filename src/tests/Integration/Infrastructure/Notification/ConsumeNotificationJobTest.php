<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Notification;

use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationAction;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Gateway\SendResult;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Domain\Notification\ValueObject\Recipient;
use App\Infrastructure\Notification\Messaging\ConsumeNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

class ConsumeNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private ConsumeNotificationJob $job;

    private $gatewayMock;

    private NotificationRepository $notificationRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gatewayMock = Mockery::mock(NotificationGateway::class);
        $this->app->instance(NotificationGateway::class, $this->gatewayMock);

        $this->notificationRepo = app(NotificationRepository::class);

        $this->job = new ConsumeNotificationJob(
            app(DeliverNotificationAction::class),
            $this->notificationRepo,
            [100, 200, 300], // backoff
            3 // max attempts
        );
    }

    public function test_it_processes_notification_successfully(): void
    {
        $notification = Notification::create(
            new Recipient('+79991234567', Channel::Sms),
            Channel::Sms,
            Priority::Transactional,
            new MessageBody('Test Consume'),
        );
        $this->notificationRepo->save($notification);

        $payload = json_encode(['notification_id' => $notification->id->value]);
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->andReturn($payload);
        $message->shouldReceive('has')->with('application_headers')->andReturn(false);
        $message->shouldReceive('ack')->once();

        $this->gatewayMock->shouldReceive('send')
            ->once()
            ->andReturn(new SendResult(new ProviderMessageId('test_msg_id')));

        ($this->job)($message);
    }
}
