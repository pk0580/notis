<?php

declare(strict_types=1);

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\Recipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('round-trips a notification', function () {
    /** @var NotificationRepository $repo */
    $repo = app(NotificationRepository::class);

    $notification = Notification::create(
        new Recipient('+79991234567', Channel::Sms),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('Test body'),
        'trace-123'
    );

    $repo->save($notification);

    $loaded = $repo->findById($notification->id);

    expect($loaded)->not->toBeNull()
        ->and($loaded->id->value)->toBe($notification->id->value)
        ->and($loaded->recipient->value)->toBe($notification->recipient->value)
        ->and($loaded->status())->toBe(NotificationStatus::Queued)
        ->and($loaded->traceId)->toBe('trace-123');
});

it('finds notifications by recipient', function () {
    /** @var NotificationRepository $repo */
    $repo = app(NotificationRepository::class);

    $recipient = new Recipient('+79991234567', Channel::Sms);

    $n1 = Notification::create($recipient, Channel::Sms, Priority::Transactional, new MessageBody('Body 1'));
    $n2 = Notification::create($recipient, Channel::Sms, Priority::Transactional, new MessageBody('Body 2'));
    $n3 = Notification::create(new Recipient('+79990000000', Channel::Sms), Channel::Sms, Priority::Transactional, new MessageBody('Body 3'));

    $repo->saveMany([$n1, $n2, $n3]);

    $found = $repo->findByRecipient($recipient->value, 10);

    expect($found)->toHaveCount(2)
        ->and($found[0]->body->value)->toBe('Body 2') // latest first
        ->and($found[1]->body->value)->toBe('Body 1');
});
