<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Gateway;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Gateway\GatewayResult;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\ValueObject\Channel;
use App\Infrastructure\Notification\Gateway\Exception\UnsupportedChannelException;

final readonly class CompositeNotificationGateway implements NotificationGateway
{
    /**
     * @param  list<NotificationGateway>  $gateways
     */
    public function __construct(private array $gateways) {}

    public function supports(Channel $channel): bool
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->supports($channel)) {
                return true;
            }
        }

        return false;
    }

    public function send(Notification $notification): GatewayResult
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->supports($notification->channel)) {
                return $gateway->send($notification);
            }
        }

        throw UnsupportedChannelException::forChannel($notification->channel);
    }
}
