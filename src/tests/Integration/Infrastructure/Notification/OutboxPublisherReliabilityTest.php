<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Notification;

use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\OutboxMessageModel;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxPublisherReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private OutboxPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        app(RabbitMqTopology::class)->declare();
        $this->publisher = app(OutboxPublisher::class);
    }

    public function test_it_reserves_messages_and_updates_on_success(): void
    {
        // Создаем уведомление, чтобы join работал
        $notification = NotificationModel::create([
            'id' => Str::uuid(),
            'recipient' => '+79991112233',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Test',
            'status' => 'queued',
        ]);

        $outbox = OutboxMessageModel::create([
            'notification_id' => $notification->id,
            'priority' => 'transactional',
        ]);

        $count = $this->publisher->flush(1);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $outbox->id,
            'reserved_at' => null, // Должен быть сброшен после успеха
        ]);
        
        $updated = OutboxMessageModel::find($outbox->id);
        $this->assertNotNull($updated->published_at);
        $this->assertEquals(1, $updated->attempts);
    }

    public function test_it_records_error_and_releases_reservation_on_failure(): void
    {
        $notification = NotificationModel::create([
            'id' => Str::uuid(),
            'recipient' => '+79991112233',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Test',
            'status' => 'queued',
        ]);

        $outbox = OutboxMessageModel::create([
            'notification_id' => $notification->id,
            'priority' => 'transactional',
        ]);

        // Используем некорректные данные для RabbitMQ, чтобы вызвать ошибку
        $brokenPublisher = new OutboxPublisher('localhost', 5672, 'wrong_user', 'wrong_pass');
        
        try {
            $brokenPublisher->flush(1);
            $this->fail('Expected exception was not thrown');
        } catch (\Throwable $e) {
            // Success
        }

        $updated = OutboxMessageModel::find($outbox->id);
        $this->assertNull($updated->published_at);
        $this->assertNull($updated->reserved_at, 'Message should be released');
        $this->assertNotNull($updated->available_at, 'Available at should be set for backoff');
        $this->assertNotNull($updated->last_error);
        $this->assertEquals(1, $updated->attempts);
    }
}
