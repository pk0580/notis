<?php

declare(strict_types=1);

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Exception\ConcurrencyException;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\ProviderMessageId;
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

it('persists multiple notifications via variadic saveMany', function () {
    /** @var NotificationRepository $repo */
    $repo = app(NotificationRepository::class);

    $n1 = Notification::create(
        new Recipient('+79991234567', Channel::Sms),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('Body 1')
    );
    $n2 = Notification::create(
        new Recipient('+79990000000', Channel::Sms),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('Body 2')
    );

    $repo->saveMany($n1, $n2);

    expect($repo->findById($n1->id))->not->toBeNull()
        ->and($repo->findById($n2->id))->not->toBeNull();
});

it('rejects stale writes with ConcurrencyException', function () {
    /** @var NotificationRepository $repo */
    $repo = app(NotificationRepository::class);

    $notification = Notification::create(
        new Recipient('+79991234567', Channel::Sms),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('Test')
    );
    $repo->save($notification);

    $stale = $repo->findById($notification->id);

    $notification->markAsSent(new ProviderMessageId('pid-1'));
    $repo->save($notification);

    $stale->markAsSent(new ProviderMessageId('pid-2'));
    expect(fn () => $repo->save($stale))->toThrow(ConcurrencyException::class);
});
