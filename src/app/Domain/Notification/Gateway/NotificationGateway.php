<?php

declare(strict_types=1);

namespace App\Domain\Notification\Gateway;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Exception\GatewayRejectedException;
use App\Domain\Notification\Exception\GatewayTimeoutException;
use App\Domain\Notification\Exception\GatewayUnavailableException;
use App\Domain\Notification\ValueObject\Channel;

interface NotificationGateway
{
    public function supports(Channel $channel): bool;

    /**
     * @throws GatewayTimeoutException        transient → retry
     * @throws GatewayUnavailableException    transient → retry
     * @throws GatewayRejectedException       permanent → dropped without retry
     */
    public function send(Notification $notification): GatewayResult;
}
