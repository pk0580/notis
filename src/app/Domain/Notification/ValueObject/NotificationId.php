<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

use Ramsey\Uuid\Uuid;
use InvalidArgumentException;

final readonly class NotificationId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidArgumentException("Invalid NotificationId: $value");
        }
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
