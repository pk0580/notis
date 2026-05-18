<?php

declare(strict_types=1);

namespace App\Domain\Notification\Gateway;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\ValueObject\ProviderMessageId;

final readonly class SendResult
{
    public function __construct(public ProviderMessageId $messageId) {}
}

interface NotificationGateway
{
    public function send(Notification $notification): SendResult;
}
