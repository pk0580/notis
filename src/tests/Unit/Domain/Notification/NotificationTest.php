<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Exception\InvalidNotificationStatusTransitionException;
use App\Domain\Notification\Exception\InvalidRecipientException;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Domain\Notification\ValueObject\Recipient;

it('creates a notification with queued status', function () {
    $recipient = Recipient::fromString(Channel::Sms, '+1234567890');
    $channel = Channel::Sms;
    $priority = Priority::Transactional;
    $body = new MessageBody('Hello world');
    $traceId = 'trace-123';

    $notification = Notification::create($recipient, $channel, $priority, $body, $traceId);

    expect($notification->status())->toBe(NotificationStatus::Queued)
        ->and($notification->recipient->equals($recipient))->toBeTrue()
        ->and($notification->channel)->toBe($channel)
        ->and($notification->priority)->toBe($priority)
        ->and($notification->body->value)->toBe('Hello world')
        ->and($notification->traceId)->toBe($traceId)
        ->and($notification->attempts())->toBe(0)
        ->and($notification->history()->items)->toHaveCount(1)
        ->and($notification->history()->items[0]['status'])->toBe(NotificationStatus::Queued->value);
});

it('validates sms recipient format', function () {
    expect(fn () => Recipient::fromString(Channel::Sms, 'invalid-sms'))
        ->toThrow(InvalidRecipientException::class);
});

it('validates email recipient format', function () {
    expect(fn () => Recipient::fromString(Channel::Email, 'invalid-email'))
        ->toThrow(InvalidRecipientException::class);
});

it('masks recipients correctly', function () {
    $sms = Recipient::fromString(Channel::Sms, '+1234567890');
    expect($sms->masked())->toBe('+1***7890');

    $email = Recipient::fromString(Channel::Email, 'user@example.com');
    expect($email->masked())->toBe('u***@example.com');
});

it('transitions to sent status', function () {
    $notification = Notification::create(
        Recipient::fromString(Channel::Sms, '+1234567890'),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('test')
    );

    $providerId = new ProviderMessageId('p-123');
    $notification->markAsSent($providerId);

    expect($notification->status())->toBe(NotificationStatus::Sent)
        ->and($notification->providerMessageId()->value)->toBe('p-123')
        ->and($notification->history()->items)->toHaveCount(2)
        ->and($notification->history()->items[1]['status'])->toBe(NotificationStatus::Sent->value);
});

it('transitions from sent to delivered', function () {
    $notification = Notification::create(
        Recipient::fromString(Channel::Sms, '+1234567890'),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('test')
    );
    $notification->markAsSent(new ProviderMessageId('p-123'));
    $notification->markAsDelivered();

    expect($notification->status())->toBe(NotificationStatus::Delivered)
        ->and($notification->history()->items)->toHaveCount(3)
        ->and($notification->history()->items[2]['status'])->toBe(NotificationStatus::Delivered->value);
});

it('transitions from queued or sent to dropped', function () {
    // From Queued
    $n1 = Notification::create(
        Recipient::fromString(Channel::Sms, '+1234567890'),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('test')
    );
    $n1->markAsDropped('reason 1');
    expect($n1->status())->toBe(NotificationStatus::Dropped);

    // From Sent
    $n2 = Notification::create(
        Recipient::fromString(Channel::Sms, '+1234567890'),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('test')
    );
    $n2->markAsSent(new ProviderMessageId('p-123'));
    $n2->markAsDropped('reason 2');
    expect($n2->status())->toBe(NotificationStatus::Dropped);
});

it('throws exception on invalid status transition', function () {
    $notification = Notification::create(
        Recipient::fromString(Channel::Sms, '+1234567890'),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('test')
    );

    // Cannot go directly to delivered from queued
    expect(fn () => $notification->markAsDelivered())
        ->toThrow(InvalidNotificationStatusTransitionException::class);
});

it('records failed attempts', function () {
    $notification = Notification::create(
        Recipient::fromString(Channel::Sms, '+1234567890'),
        Channel::Sms,
        Priority::Transactional,
        new MessageBody('test')
    );

    $notification->recordFailedAttempt('Error 1');
    expect($notification->attempts())->toBe(1)
        ->and($notification->lastError())->toBe('Error 1');

    $notification->recordFailedAttempt('Error 2');
    expect($notification->attempts())->toBe(2)
        ->and($notification->lastError())->toBe('Error 2');
});
