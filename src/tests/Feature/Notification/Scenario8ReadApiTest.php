<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Scenario8ReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_api_returns_history_and_masked_recipient(): void
    {
        $recipient = '+79991112233';
        NotificationModel::create([
            'id' => Str::uuid()->toString(),
            'recipient' => $recipient,
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'History Test',
            'status' => 'delivered',
            'status_history' => [
                ['status' => 'queued', 'at' => now()->subMinutes(5)->toIso8601String()],
                ['status' => 'sent', 'at' => now()->subMinutes(4)->toIso8601String()],
                ['status' => 'delivered', 'at' => now()->subMinutes(3)->toIso8601String()],
            ],
            'version' => 3,
        ]);

        $response = $this->getJson('/api/v1/notifications?recipient='.urlencode($recipient));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');

        $item = $response->json('data.0');
        $this->assertEquals('delivered', $item['status']);
        $this->assertEquals('+7***2233', $item['recipient_masked']);
        $this->assertArrayNotHasKey('recipient', $item);

        $this->assertCount(3, $item['status_history']);
        $this->assertEquals('queued', $item['status_history'][0]['status']);
    }
}
