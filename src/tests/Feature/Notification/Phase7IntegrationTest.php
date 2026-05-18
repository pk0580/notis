<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationAction;
use App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationData;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationPriority;
use App\Infrastructure\Notification\Job\SimulateDeliveryAckJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class Phase7IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_delivery_chain_with_gateways_and_ack_job(): void
    {
        // 1. Подготовка
        /** @var NotificationRepository $repository */
        $repository = app(NotificationRepository::class);
        $notification = Notification::create(
            Channel::Sms,
            'transactional',
            'test@example.com',
            'Hello'
        );
        $repository->save($notification);

        // Очищаем Redis перед тестом
        Redis::del('gateway:idempotency:sms');

        // 2. Выполняем DeliverNotificationAction
        /** @var DeliverNotificationAction $action */
        $action = app(DeliverNotificationAction::class);
        
        // Фейкаем очередь, чтобы проверить диспатч
        Queue::fake(['database']);

        $action->handle(new DeliverNotificationData($notification->id));

        // 3. Проверяем состояние после отправки
        $updated = $repository->findById($notification->id);
        $this->assertEquals('sent', $updated->status()->value);
        $this->assertNotNull($updated->providerMessageId());

        // 4. Проверяем дедупликацию в Redis
        $cachedId = Redis::hget('gateway:idempotency:sms', $notification->id->value);
        $this->assertEquals($updated->providerMessageId()->value, $cachedId);

        // 5. Проверяем диспатч SimulateDeliveryAckJob на database queue
        Queue::assertPushed(SimulateDeliveryAckJob::class, function ($job) use ($notification) {
            return $job->queue === 'default'; 
        });
        
        // В Laravel Queue::fake() перехватывает все, поэтому на connection 'database' мы не увидим записи в таблице jobs.
        // Но мы проверили сам факт диспатча.
    }

    public function test_gateway_idempotency_prevents_duplicate_provider_calls(): void
    {
        /** @var NotificationRepository $repository */
        $repository = app(NotificationRepository::class);
        $notification = Notification::create(Channel::Sms, 'transactional', 'test@example.com', 'Hello');
        $repository->save($notification);

        Redis::hset('gateway:idempotency:sms', $notification->id->value, 'existing_msg_id');

        /** @var DeliverNotificationAction $action */
        $action = app(DeliverNotificationAction::class);
        $action->handle(new DeliverNotificationData($notification->id));

        $updated = $repository->findById($notification->id);
        $this->assertEquals('existing_msg_id', $updated->providerMessageId()->value);
    }

    public function test_ack_job_completes_notification_status(): void
    {
        /** @var NotificationRepository $repository */
        $repository = app(NotificationRepository::class);
        $notification = Notification::create(Channel::Sms, 'transactional', 'test@example.com', 'Hello');
        $notification->markAsSent(new \App\Domain\Notification\ValueObject\ProviderMessageId('msg_123'));
        $repository->save($notification);

        $job = new SimulateDeliveryAckJob($notification->id->value);
        app()->call([$job, 'handle']);

        $updated = $repository->findById($notification->id);
        $this->assertContains($updated->status()->value, ['delivered', 'dropped']);
    }
}
