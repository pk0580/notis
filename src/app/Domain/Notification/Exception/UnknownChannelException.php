<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exception;

use DomainException;

final class UnknownChannelException extends DomainException
{
    public static function forValue(string $value): self
    {
        return new self("Unknown channel: {$value}");
    }
}
