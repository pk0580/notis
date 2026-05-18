<?php

declare(strict_types=1);

namespace App\Domain\Notification\Gateway;

use App\Domain\Notification\ValueObject\ProviderMessageId;

final readonly class GatewayResult
{
    public function __construct(public ProviderMessageId $messageId) {}
}
