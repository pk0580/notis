<?php

declare(strict_types=1);

use App\Application\Notification\Outbox\OutboxEntry;
use App\Application\Notification\Outbox\OutboxRepository;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\Recipient;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\OutboxMessageModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('bulk-appends outbox entries inside the current transaction', function () {
    /** @var NotificationRepository $notificationRepo */
    $notificationRepo = app(NotificationRepository::class);

    $notification = Notification::create(
        new Recipient('+79991234567', Channel::Sms),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('Test'),
    );
    $notificationRepo->save($notification);

    /** @var OutboxRepository $repo */
    $repo = app(OutboxRepository::class);

    $repo->appendMany([new OutboxEntry($notification->id, $notification->priority)]);

    $rows = OutboxMessageModel::query()->whereNull('published_at')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->notification_id)->toBe($notification->id->value)
        ->and($rows[0]->priority)->toBe('transactional');
});
