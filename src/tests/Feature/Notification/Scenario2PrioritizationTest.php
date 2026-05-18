<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Integration\RabbitMqIntegrationTestCase;

class Scenario2PrioritizationTest extends RabbitMqIntegrationTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
        app(RabbitMqTopology::class)->declare();
    }

    public function test_prioritization_using_different_queues(): void
    {
        // 1. Send Marketing message
        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/v1/notifications', [
                'channel' => 'sms',
                'priority' => 'marketing',
                'body' => 'Marketing Body',
                'recipients' => ['+79991112233'],
            ])->assertAccepted();

        // 2. Send Transactional message
        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/v1/notifications', [
                'channel' => 'sms',
                'priority' => 'transactional',
                'body' => 'Transactional Body',
                'recipients' => ['+79994445566'],
            ])->assertAccepted();

        // 3. Flush Outbox
        /** @var OutboxPublisher $publisher */
        $publisher = app(OutboxPublisher::class);
        $publisher->flush(10);

        // 4. Verify queues
        $channel = $this->rabbitmqConnection->channel();

        $msgTransactional = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL);
        $this->assertNotNull($msgTransactional, 'Transactional message not found in transactional queue');
        $payloadT = json_decode($msgTransactional->getBody(), true);

        $msgMarketing = $channel->basic_get(RabbitMqTopology::QUEUE_MARKETING);
        $this->assertNotNull($msgMarketing, 'Marketing message not found in marketing queue');
        $payloadM = json_decode($msgMarketing->getBody(), true);

        $this->assertNotEquals($payloadT['notification_id'], $payloadM['notification_id']);
    }
}
