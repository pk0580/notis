<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

use App\Domain\Notification\Exception\InvalidMessageBodyException;

final readonly class MessageBody
{
    public const int MAX_LENGTH_SMS = 1000;

    public const int MAX_LENGTH_EMAIL = 10000;

    public function __construct(public string $value) {}

    public static function for(Channel $channel, string $body): self
    {
        $max = self::maxLengthFor($channel);

        if ($body === '' || mb_strlen($body) > $max) {
            throw new InvalidMessageBodyException("Message body must be between 1 and {$max} characters.");
        }

        return new self($body);
    }

    public static function maxLengthFor(Channel $channel): int
    {
        return match ($channel) {
            Channel::Sms => self::MAX_LENGTH_SMS,
            Channel::Email => self::MAX_LENGTH_EMAIL,
        };
    }
}
