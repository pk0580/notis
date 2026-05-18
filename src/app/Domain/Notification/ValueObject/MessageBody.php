<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

use App\Domain\Notification\Exception\InvalidMessageBodyException;

final readonly class MessageBody
{
    public function __construct(public string $value)
    {
    }

    public static function for(Channel $channel, string $body): self
    {
        $max = match ($channel) {
            Channel::Sms => (int) config('notifications.body_max.sms', 1000),
            Channel::Email => (int) config('notifications.body_max.email', 10000),
        };

        if ($body === '' || mb_strlen($body) > $max) {
            throw new InvalidMessageBodyException("Message body must be between 1 and $max characters.");
        }

        return new self($body);
    }
}
