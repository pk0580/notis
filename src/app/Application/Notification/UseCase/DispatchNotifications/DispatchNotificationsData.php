<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\DispatchNotifications;

use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\Recipient;

final readonly class DispatchNotificationsData
{
    /**
     * @param Recipient[] $recipients
     */
    public function __construct(
        public Channel $channel,
        public Priority $priority,
        public MessageBody $body,
        public array $recipients,
        public ?string $traceId = null,
    ) {}
}
