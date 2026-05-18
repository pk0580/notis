<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Domain\Notification\Gateway\SendResult;
use App\Domain\Notification\Exception\GatewayUnavailableException;
use App\Domain\Notification\Exception\GatewayRejectedException;
use App\Infrastructure\Notification\Messaging\ConsumeNotificationJob;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\Integration\RabbitMqIntegrationTestCase;

class RetryTest extends RabbitMqIntegrationTestCase
{
    use RefreshDatabase;

    private $gatewayMock;

    protected function setUp(): void
    {
        parent::setUp();
        app(RabbitMqTopology::class)->declare();
        $this->gatewayMock = Mockery::mock(NotificationGateway::class);
        app()->instance(NotificationGateway::class, $this->gatewayMock);
    }

    public function test_scenario_4_transient_failure_retries(): void
    {
        $notification = NotificationModel::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'recipient' => '+79991234567',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Retry Test',
            'status' => 'queued',
            'version' => 0,
        ]);

        // Publish real message
        $channel = $this->rabbitmqConnection->channel();
        $payload = json_encode(['notification_id' => $notification->id]);
        $msg = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);
        $msg->set('application_headers', new AMQPTable(['x-retries' => 0]));
        $channel->basic_publish($msg, RabbitMqTopology::EXCHANGE_DIRECT, 'transactional');

        // Get it back to have channel info
        $receivedMsg = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL);
        $this->assertNotNull($receivedMsg);

        // Mock transient failure
        $this->gatewayMock->shouldReceive('send')->once()->andThrow(new GatewayUnavailableException('Service down'));

        /** @var ConsumeNotificationJob $consumer */
        $consumer = app(ConsumeNotificationJob::class);
        $consumer($receivedMsg);

        // Verify status is still queued, but attempts increased
        $notification->refresh();
        $this->assertEquals('queued', $notification->status);
        $this->assertEquals(1, $notification->attempts);
        $this->assertEquals('Service down', $notification->last_error);

        // Verify message is in retry queue
        $retryMsg = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL_RETRY);
        $this->assertNotNull($retryMsg);
        $headers = $retryMsg->get('application_headers')->getNativeData();
        $this->assertEquals(1, $headers['x-retries']);
    }

    public function test_scenario_5_max_retries_exhausted_to_dlq(): void
    {
        $notification = NotificationModel::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'recipient' => '+79991234567',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'DLQ Test',
            'status' => 'queued',
            'attempts' => 4,
            'version' => 0,
        ]);

        // Publish real message with 4 retries
        $channel = $this->rabbitmqConnection->channel();
        $payload = json_encode(['notification_id' => $notification->id]);
        $msg = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);
        $msg->set('application_headers', new AMQPTable(['x-retries' => 4]));
        $channel->basic_publish($msg, RabbitMqTopology::EXCHANGE_DIRECT, 'transactional');

        // Get it back
        $receivedMsg = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL);
        $this->assertNotNull($receivedMsg);

        // Mock failure on 5th attempt
        $this->gatewayMock->shouldReceive('send')->once()->andThrow(new GatewayUnavailableException('Still down'));

        /** @var ConsumeNotificationJob $consumer */
        $consumer = app(ConsumeNotificationJob::class);
        $consumer($receivedMsg);

        // Verify status is dropped
        $notification->refresh();
        $this->assertEquals('dropped', $notification->status);
        $this->assertEquals(5, $notification->attempts);

        // Verify message is in DLQ
        $dlqMsg = $channel->basic_get(RabbitMqTopology::QUEUE_DLQ);
        $this->assertNotNull($dlqMsg);
    }

    public function test_scenario_6_permanent_reject_to_dlq(): void
    {
        $notification = NotificationModel::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'recipient' => '+79991234567',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Reject Test',
            'status' => 'queued',
            'version' => 0,
        ]);

        // Publish real message
        $channel = $this->rabbitmqConnection->channel();
        $payload = json_encode(['notification_id' => $notification->id]);
        $msg = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);
        $channel->basic_publish($msg, RabbitMqTopology::EXCHANGE_DIRECT, 'transactional');

        // Get it back
        $receivedMsg = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL);
        $this->assertNotNull($receivedMsg);

        // Mock permanent reject
        $this->gatewayMock->shouldReceive('send')->once()->andThrow(new GatewayRejectedException('Invalid recipient'));

        /** @var ConsumeNotificationJob $consumer */
        $consumer = app(ConsumeNotificationJob::class);
        $consumer($receivedMsg);

        // Verify status is dropped
        $notification->refresh();
        $this->assertEquals('dropped', $notification->status);

        // Verify message is in DLQ
        $dlqMsg = $channel->basic_get(RabbitMqTopology::QUEUE_DLQ);
        $this->assertNotNull($dlqMsg);
    }

    public function test_scenario_7_exactly_once_protection(): void
    {
        $notification = NotificationModel::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'recipient' => '+79991234567',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Exactly Once',
            'status' => 'sent', // Already sent
            'provider_message_id' => 'existing-pid',
            'version' => 1,
        ]);

        // Publish real message
        $channel = $this->rabbitmqConnection->channel();
        $payload = json_encode(['notification_id' => $notification->id]);
        $msg = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);
        $channel->basic_publish($msg, RabbitMqTopology::EXCHANGE_DIRECT, 'transactional');

        // Get it back
        $receivedMsg = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL);
        $this->assertNotNull($receivedMsg);

        // Gateway should NOT be called
        $this->gatewayMock->shouldNotReceive('send');

        /** @var ConsumeNotificationJob $consumer */
        $consumer = app(ConsumeNotificationJob::class);
        $consumer($receivedMsg);

        // Status should remain 'sent'
        $notification->refresh();
        $this->assertEquals('sent', $notification->status);
    }
}
