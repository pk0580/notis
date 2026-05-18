<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Str;

uses(TestCase::class, RefreshDatabase::class);

it('returns notifications for recipient', function () {
    NotificationModel::create([
        'id' => Str::uuid()->toString(),
        'recipient' => '+79991234567',
        'channel' => 'sms',
        'priority' => 'high',
        'body' => 'Test body',
        'status' => 'sent',
        'status_history' => [['status' => 'queued', 'at' => now()->toIso8601String()]],
        'attempts' => 1,
    ]);

    $response = $this->getJson('/api/v1/notifications?recipient=' . urlencode('+79991234567'));

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'channel',
                    'priority',
                    'status',
                    'recipient_masked',
                    'attempts',
                    'last_error',
                    'status_history',
                    'created_at',
                    'updated_at',
                ]
            ],
            'meta' => ['next_cursor']
        ])
        ->assertJsonFragment([
            'recipient_masked' => '+7***4567'
        ]);
});

it('masks email recipients', function () {
    NotificationModel::create([
        'id' => Str::uuid()->toString(),
        'recipient' => 'john.doe@example.com',
        'channel' => 'email',
        'priority' => 'low',
        'body' => 'Test body',
        'status' => 'sent',
    ]);

    $response = $this->getJson('/api/v1/notifications?recipient=john.doe@example.com');

    $response->assertOk()
        ->assertJsonFragment([
            'recipient_masked' => 'j***@example.com'
        ]);
});

it('validates recipient', function () {
    $response = $this->getJson('/api/v1/notifications?recipient=invalid');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['recipient' => 'invalid_recipient']);
});

it('paginates results', function () {
    for ($i = 0; $i < 5; $i++) {
        NotificationModel::create([
            'id' => Str::uuid()->toString(),
            'recipient' => 'test@example.com',
            'channel' => 'email',
            'priority' => 'low',
            'body' => 'Test body ' . $i,
            'status' => 'queued',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $response = $this->getJson('/api/v1/notifications?recipient=test@example.com&per_page=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
    
    $nextCursor = $response->json('meta.next_cursor');
    expect($nextCursor)->not->toBeNull();

    $response2 = $this->getJson('/api/v1/notifications?recipient=test@example.com&per_page=2&cursor=' . $nextCursor);
    $response2->assertOk()
        ->assertJsonCount(2, 'data');
});
