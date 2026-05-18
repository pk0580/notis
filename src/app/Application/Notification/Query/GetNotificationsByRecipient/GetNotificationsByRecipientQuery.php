<?php

declare(strict_types=1);

namespace App\Application\Notification\Query\GetNotificationsByRecipient;

use App\Domain\Notification\ValueObject\Recipient;

final readonly class GetNotificationsByRecipientQuery
{
    public function __construct(
        public Recipient $recipient,
        public ?string $cursor = null,
        public int $perPage = 20,
    ) {}
}
