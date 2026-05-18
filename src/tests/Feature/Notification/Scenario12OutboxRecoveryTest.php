<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\RabbitMqIntegrationTestCase;

class Scenario12OutboxRecoveryTest extends RabbitMqIntegrationTestCase
{
    use RefreshDatabase;

    public function test_outbox_recovery(): void
    {
        // 1. Manually insert message into outbox as 'not published'
        $notificationId = Str::uuid()->toString();
        NotificationModel::create([
            'id' => $notificationId,
            'recipient' => '+79990001122',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Recovery Test',
            'status' => 'queued',
            'version' => 0,
        ]);

        DB::table('outbox_messages')->insert([
            'id' => Str::uuid()->toString(),
            'notification_id' => $notificationId,
            'priority' => 'transactional',
            'created_at' => now()->subMinutes(10),
            'published_at' => null,
        ]);

        // 2. Run flush
        app(RabbitMqTopology::class)->declare();
        /** @var OutboxPublisher $publisher */
        $publisher = app(OutboxPublisher::class);
        $count = $publisher->flush(10);
        $this->assertEquals(1, $count);

        // 3. Verify it is now published
        $this->assertNotNull(
            DB::table('outbox_messages')
                ->where('notification_id', $notificationId)
                ->value('published_at')
        );

        // 4. Verify message in RabbitMQ
        $channel = $this->rabbitmqConnection->channel();
        $msg = $channel->basic_get(RabbitMqTopology::QUEUE_TRANSACTIONAL);
        $this->assertNotNull($msg);
    }
}
