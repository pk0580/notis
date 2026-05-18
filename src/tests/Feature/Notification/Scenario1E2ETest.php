<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Application\Notification\UseCase\AcknowledgeDelivery\AcknowledgeDeliveryAction;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Gateway\SendResult;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Infrastructure\Notification\Job\SimulateDeliveryAckJob;
use App\Infrastructure\Notification\Messaging\ConsumeNotificationJob;
use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\Integration\RabbitMqIntegrationTestCase;

class Scenario1E2ETest extends RabbitMqIntegrationTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
        app(RabbitMqTopology::class)->declare();

        // Force success in gateway for e2e test
        $gatewayMock = Mockery::mock(NotificationGateway::class);
        $gatewayMock->shouldReceive('send')->andReturn(new SendResult(new ProviderMessageId('e2e-msg-123')));
        app()->instance(NotificationGateway::class, $gatewayMock);
    }

    public function test_full_e2e_chain(): void
    {
        // 1. Dispatch via API
        $idempotencyKey = Str::uuid()->toString();
        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/notifications', [
                'channel' => 'sms',
                'priority' => 'transactional',
                'body' => 'E2E Test Body',
                'recipients' => ['+79990001122'],
            ]);

        $response->assertStatus(202);
        $notificationId = $response->json('data.notification_ids.0');

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'status' => 'queued',
        ]);

        // 2. Publish from Outbox
        /** @var OutboxPublisher $publisher */
        $publisher = app(OutboxPublisher::class);
        $publishedCount = $publisher->flush(10);
        $this->assertEquals(1, $publishedCount);

        $this->assertDatabaseHas('outbox_messages', [
            'notification_id' => $notificationId,
        ]);
        // Check published_at is not null
        $this->assertNotNull(
            DB::table('outbox_messages')
                ->where('notification_id', $notificationId)
                ->value('published_at')
        );

        // 3. Read from real RabbitMQ
        $channel = $this->rabbitmqConnection->channel();
        $msg = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL);
        $this->assertNotNull($msg, 'Message not found in RabbitMQ queue');

        $payload = json_decode($msg->getBody(), true);
        $this->assertEquals($notificationId, $payload['notification_id']);
        $this->assertEquals(0, $msg->get('application_headers')->getNativeData()['x-retries'] ?? 0);

        // 4. Consume
        /** @var ConsumeNotificationJob $consumer */
        $consumer = app(ConsumeNotificationJob::class);
        $consumer($msg); // This should call DeliverNotificationAction and ACK

        // 5. Verify status 'sent'
        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'status' => 'sent',
        ]);
        $notification = NotificationModel::find($notificationId);
        $this->assertNotNull($notification->provider_message_id);

        // 6. Simulate Delivery Ack
        $job = new SimulateDeliveryAckJob($notificationId);
        $job->handle(
            app(AcknowledgeDeliveryAction::class)
        );

        // 7. Final status check
        $notification->refresh();
        $this->assertContains($notification->status, ['delivered', 'dropped']);

        // Check history
        $history = $notification->status_history;
        $statuses = array_column($history, 'status');
        $this->assertContains('queued', $statuses);
        $this->assertContains('sent', $statuses);
        $this->assertContains($notification->status, $statuses);
    }
}
