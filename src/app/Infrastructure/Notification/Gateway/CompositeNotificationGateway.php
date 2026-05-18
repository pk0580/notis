<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Gateway;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Gateway\SendResult;
use InvalidArgumentException;

final readonly class CompositeNotificationGateway implements NotificationGateway
{
    /**
     * @param  array<string, NotificationGateway>  $gateways
     */
    public function __construct(private array $gateways) {}

    public function send(Notification $notification): SendResult
    {
        $channel = $notification->channel->value;

        if (! isset($this->gateways[$channel])) {
            throw new InvalidArgumentException("No gateway registered for channel: {$channel}");
        }

        return $this->gateways[$channel]->send($notification);
    }
}
