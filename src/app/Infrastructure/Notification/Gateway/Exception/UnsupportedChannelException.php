<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Gateway\Exception;

use App\Domain\Notification\ValueObject\Channel;
use RuntimeException;

final class UnsupportedChannelException extends RuntimeException
{
    public static function forChannel(Channel $channel): self
    {
        return new self("No gateway registered for channel: {$channel->value}");
    }
}
