<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Notification\Console\Command;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxPurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_old_published_messages(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('outbox.purged', \Mockery::on(fn ($args) => $args['count'] === 2));

        // 1. Создаем уведомления (нужны для foreign key)
        $n1 = (string) Str::uuid();
        $n2 = (string) Str::uuid();
        $n3 = (string) Str::uuid();

        DB::table('notifications')->insert([
            ['id' => $n1, 'recipient' => 'test1@example.com', 'channel' => 'email', 'priority' => 'transactional', 'body' => 't', 'status' => 'delivered', 'status_history' => '[]', 'created_at' => now()->subDays(10)],
            ['id' => $n2, 'recipient' => 'test2@example.com', 'channel' => 'email', 'priority' => 'transactional', 'body' => 't', 'status' => 'delivered', 'status_history' => '[]', 'created_at' => now()->subDays(10)],
            ['id' => $n3, 'recipient' => 'test3@example.com', 'channel' => 'email', 'priority' => 'transactional', 'body' => 't', 'status' => 'queued',    'status_history' => '[]', 'created_at' => now()],
        ]);

        // 2. Создаем записи в outbox
        DB::table('outbox_messages')->insert([
            // Старая опубликованная (должна удалиться)
            ['id' => Str::uuid(), 'notification_id' => $n1, 'priority' => 'transactional', 'published_at' => now()->subDays(8), 'created_at' => now()->subDays(10)],
            // Еще одна старая опубликованная (должна удалиться)
            ['id' => Str::uuid(), 'notification_id' => $n2, 'priority' => 'transactional', 'published_at' => now()->subDays(10), 'created_at' => now()->subDays(12)],
            // Новая опубликованная (должна остаться)
            ['id' => Str::uuid(), 'notification_id' => $n3, 'priority' => 'transactional', 'published_at' => now()->subMinutes(5), 'created_at' => now()->subMinutes(10)],
            // Неопубликованная (должна остаться)
            ['id' => Str::uuid(), 'notification_id' => $n3, 'priority' => 'transactional', 'published_at' => null, 'created_at' => now()->subDays(10)],
        ]);

        $this->artisan('outbox:purge --days=7')
            ->expectsOutputToContain('Deleted 2 messages')
            ->expectsOutputToContain('Total deleted: 2')
            ->assertSuccessful();

        $this->assertEquals(2, DB::table('outbox_messages')->count());
    }

    public function test_it_does_nothing_if_no_old_messages(): void
    {
        Log::spy();

        $n1 = (string) Str::uuid();
        DB::table('notifications')->insert([
            ['id' => $n1, 'recipient' => 'test1@example.com', 'channel' => 'email', 'priority' => 'transactional', 'body' => 't', 'status' => 'delivered', 'status_history' => '[]', 'created_at' => now()],
        ]);

        DB::table('outbox_messages')->insert([
            ['id' => Str::uuid(), 'notification_id' => $n1, 'priority' => 'transactional', 'published_at' => now(), 'created_at' => now()],
        ]);

        $this->artisan('outbox:purge --days=7')
            ->expectsOutputToContain('Total deleted: 0')
            ->assertSuccessful();

        $this->assertEquals(1, DB::table('outbox_messages')->count());
        Log::shouldNotHaveReceived('info');
    }
}
