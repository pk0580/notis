<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Notification;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\Recipient;
use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\OutboxMessageModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboxPublisherTest extends TestCase
{
    use RefreshDatabase;

    private OutboxPublisher $publisher;

    private RabbitMqTopology $topology;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = app(OutboxPublisher::class);
        $this->topology = app(RabbitMqTopology::class);

        // В тестах мы можем захотеть очистить RabbitMQ, но это опасно в общей среде.
        // Поэтому мы просто проверим, что вызов проходит без ошибок, если RabbitMQ доступен.
        try {
            $this->topology->declare();
        } catch (\Throwable $e) {
            $this->markTestSkipped('RabbitMQ not available: '.$e->getMessage());
        }
    }

    public function test_it_publishes_messages_from_outbox(): void
    {
        /** @var NotificationRepository $notificationRepo */
        $notificationRepo = app(NotificationRepository::class);

        $notification = Notification::create(
            new Recipient('+79991234567', Channel::Sms),
            Channel::Sms,
            Priority::Transactional,
            new MessageBody('Test Outbox'),
        );
        $notificationRepo->save($notification);

        // Имитируем запись в outbox (обычно это делает Action через репозиторий)
        OutboxMessageModel::create([
            'notification_id' => $notification->id->value,
            'priority' => 'transactional',
        ]);

        $publishedCount = $this->publisher->flush(1);

        $this->assertEquals(1, $publishedCount);
        $this->assertDatabaseHas('outbox_messages', [
            'notification_id' => $notification->id->value,
        ]);

        $outbox = OutboxMessageModel::where('notification_id', $notification->id->value)->first();
        $this->assertNotNull($outbox->published_at);
    }
}
