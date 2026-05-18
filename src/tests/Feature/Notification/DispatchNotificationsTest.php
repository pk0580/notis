<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Redis::flushall();
});

it('accepts notification dispatch request', function () {
    $response = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
        ->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Hello!',
            'recipients' => ['+79991112233'],
        ]);

    $response->assertStatus(202)
        ->assertHeader('X-Trace-Id')
        ->assertJsonStructure([
            'data' => [
                'accepted',
                'notification_ids',
            ],
        ])
        ->assertJsonPath('data.accepted', true)
        ->assertJsonCount(1, 'data.notification_ids');

    $this->assertDatabaseHas('notifications', [
        'recipient' => '+79991112233',
        'channel' => 'sms',
        'priority' => 'transactional',
        'body' => 'Hello!',
        'status' => 'queued',
    ]);
});

it('returns cached response for same idempotency key', function () {
    $key = Str::uuid()->toString();
    $payload = [
        'channel' => 'sms',
        'priority' => 'transactional',
        'body' => 'Hello!',
        'recipients' => ['+79991112233'],
    ];

    $response1 = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/notifications', $payload);

    $response1->assertStatus(202);
    $data1 = $response1->json();

    $response2 = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/notifications', $payload);

    $response2->assertStatus(202);
    expect($response2->json())->toBe($data1);

    // Check that only one notification was created in DB
    expect(NotificationModel::count())->toBe(1);
});

it('rejects reused idempotency key with different payload', function () {
    $key = Str::uuid()->toString();

    $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Hello!',
            'recipients' => ['+79991112233'],
        ])->assertStatus(202);

    $response = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Different body',
            'recipients' => ['+79991112233'],
        ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_conflict');
});

it('requires idempotency key', function () {
    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms',
        'priority' => 'transactional',
        'body' => 'Hello!',
        'recipients' => ['+79991112233'],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');
});

it('validates channel and priority', function () {
    $response = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
        ->postJson('/api/v1/notifications', [
            'channel' => 'invalid',
            'priority' => 'invalid',
            'body' => 'Hello!',
            'recipients' => ['+79991112233'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['channel', 'priority']);
});

it('validates message body for channel', function () {
    // SMS too long (limit is 1000)
    $response = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
        ->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => str_repeat('a', 1001),
            'recipients' => ['+79991112233'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

it('validates recipients for channel', function () {
    $response = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
        ->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'priority' => 'transactional',
            'body' => 'Hello!',
            'recipients' => ['not-a-phone'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['recipients.0']);
});
