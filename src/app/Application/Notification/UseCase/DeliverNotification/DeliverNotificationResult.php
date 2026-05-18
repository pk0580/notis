<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\DeliverNotification;

enum DeliverNotificationResult
{
    case Success;
    case NoOp;
}
