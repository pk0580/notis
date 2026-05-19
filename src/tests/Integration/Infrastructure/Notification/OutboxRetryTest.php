<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Notification;

use App\Infrastructure\Notification\Messaging\OutboxPublisher;
use App\Infrastructure\Notification\Messaging\RabbitMqTopology;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\OutboxMessageModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxRetryTest extends TestCase
{
    use RefreshDatabase;

    private OutboxPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        app(RabbitMqTopology::class)->declare();
        $this->publisher = app(OutboxPublisher::class);
    }

    public function test_it_does_not_pick_messages_with_max_attempts(): void
    {
        $id = Str::uuid()->toString();
        DB::table('notifications')->insert([
            'id' => $id,
            'recipient' => '+79991234567',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Test',
            'status' => 'queued',
        ]);

        OutboxMessageModel::create([
            'id' => Str::uuid()->toString(),
            'notification_id' => $id,
            'priority' => 'transactional',
            'attempts' => 10,
        ]);

        $count = $this->publisher->flush(1);
        $this->assertEquals(0, $count);
    }

    public function test_it_applies_exponential_backoff_on_failure(): void
    {
        $id = Str::uuid()->toString();
        DB::table('notifications')->insert([
            'id' => $id,
            'recipient' => '+79991234567',
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Test',
            'status' => 'queued',
        ]);

        $outboxId = Str::uuid()->toString();
        OutboxMessageModel::create([
            'id' => $outboxId,
            'notification_id' => $id,
            'priority' => 'transactional',
            'attempts' => 0,
        ]);

        // First attempt (fails)
        // We need to mock a failure. Since we don't mock RabbitMQ easily here without complexity,
        // we can just force a failure by providing wrong credentials to publisher if possible,
        // but easier to just test the reserveBatch logic if it was public, or just trigger flush.
        
        // Let's use a trick: provide invalid RabbitMQ host to cause an exception
        $badPublisher = new OutboxPublisher('invalid-host', 5672, 'guest', 'guest');
        
        try {
            $badPublisher->flush(1);
        } catch (\Throwable $e) {
            // Expected
        }

        $msg = OutboxMessageModel::find($outboxId);
        $this->assertEquals(1, $msg->attempts);
        $this->assertNotNull($msg->available_at);
        
        // Should not be picked up immediately
        $count = $this->publisher->flush(1);
        $this->assertEquals(0, $count);

        // Travel time to 1 minute + 1 second
        $this->travel(61)->seconds();

        // Now it should be picked up
        // Note: we need to make sure reserved_at is NULL in failBatch for this to work immediately
        $count = $this->publisher->flush(1);
        // It might fail again because of RabbitMQ but it should have been PICKED (count reflects picked batch)
        // Wait, flush returns $messages->count() WHICH is the number of messages it TRIED to publish.
        $this->assertEquals(1, $count);
    }
}
