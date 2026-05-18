<?php

declare(strict_types=1);

use App\Application\Notification\Outbox\OutboxRepository;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\Recipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('round-trips an outbox message', function () {
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

    $repo->persist($notification->id->value, 'transactional');

    $unpublished = $repo->findUnpublished(10);

    expect($unpublished)->toHaveCount(1)
        ->and($unpublished[0]['notification_id'])->toBe($notification->id->value)
        ->and($unpublished[0]['priority'])->toBe('transactional');

    $repo->markAsPublished($unpublished[0]['id']);

    expect($repo->findUnpublished(10))->toBeEmpty();
});
