<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

final readonly class ProviderMessageId
{
    public function __construct(public string $value) {}
}
